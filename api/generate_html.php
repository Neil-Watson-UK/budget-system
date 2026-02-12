<?php
/**
 * API Endpoint to Generate Email HTML
 *
 * Receives email data as a JSON payload, constructs the full HTML,
 * and returns it in a JSON response.
 */

// Use the standard init file for configuration and security.
// The '../' is crucial because this file is in the 'api' subdirectory.
require_once __DIR__ . '/../includes/init.php';

// --- Input Validation ---
// Get the raw POST data
$jsonPayload = file_get_contents('php://input');

// Decode the JSON payload into a PHP associative array
$data = json_decode($jsonPayload, true);

// Check if decoding was successful
if (json_last_error() !== JSON_ERROR_NONE) {
    send_json_response(false, 'Invalid JSON payload received.', ['json_error' => json_last_error_msg()], 400);
    exit;
}

// Basic check for required data
if (!isset($data['article_blocks']) || !is_array($data['article_blocks'])) {
    send_json_response(false, 'Missing or invalid article_blocks data.', null, 400);
    exit;
}


// --- HTML Generation Logic ---

// This is a simplified version of your original HTML generation logic.
// You should adapt and expand this with your full template structure.
function generate_email_html($data) {
    // Use constants defined in init.php
    $primaryColor = PRIMARY_BRAND_COLOR;
    $fontFamily = DEFAULT_FONT_FAMILY;

    // Extract general data with fallbacks
    $subject = htmlspecialchars($data['subject'] ?? 'No Subject');
    $preheader = htmlspecialchars($data['preheader'] ?? '');
    $logoUrl = htmlspecialchars($data['logo_url'] ?? DEFAULT_LOGO_URL);

    // Build the HTML for each article block
    $articlesHtml = '';
    foreach ($data['article_blocks'] as $block) {
        $title = htmlspecialchars($block['title'] ?? '');
        // IMPORTANT: The body content is HTML from Quill, so it should not be escaped.
        // You MUST sanitize this on input if you are concerned about security.
        $body = $block['body'] ?? ''; 
        $ctaText = htmlspecialchars($block['cta_text'] ?? '');
        $ctaUrl = htmlspecialchars($block['cta_url'] ?? '#');
        $imageUrl = htmlspecialchars($block['image_url'] ?? '');

        // Simple template for a single column block
        if ($block['type'] === 'single') {
             $articlesHtml .= "
                <h2 style='color: {$primaryColor};'>{$title}</h2>
                " . (!empty($imageUrl) ? "<p><img src='{$imageUrl}' alt='Article Image' style='max-width: 100%; height: auto;'/></p>" : "") . "
                <div>{$body}</div>
                " . (!empty($ctaText) ? "<a href='{$ctaUrl}' style='color: {$primaryColor};'>{$ctaText}</a>" : "") . "
                <hr/>
             ";
        }
        // Add more `else if` conditions here for 'double', 'sign_off', etc.
    }

    // Wrap in the final HTML structure
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$subject}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: {$fontFamily}, sans-serif; }
    </style>
</head>
<body style="margin: 0; padding: 20px; background-color: #f4f4f4;">
    <div style="max-width: 600px; margin: auto; background: #ffffff; padding: 20px;">
        <p style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">{$preheader}</p>
        <img src="{$logoUrl}" alt="Logo" style="max-width: 150px; margin-bottom: 20px;">
        {$articlesHtml}
    </div>
</body>
</html>
HTML;
}

$generatedHtml = generate_email_html($data);

// --- Send Response ---
// Send the generated HTML back to the JavaScript in a proper JSON format
send_json_response(true, 'HTML generated successfully.', [
    'html' => $generatedHtml
]);

?>
```

And here is the updated `main.js` file. It includes a more resilient `apiCall` function and correctly targets the API endpoints that you have.


```javascript
/**
 * EmailPOS Main Application Logic
 *
 * This file controls all the client-side interactivity of the email editor,
 * including DOM manipulation, event handling, AJAX calls to the PHP backend,
 * and live preview updates.
 *
 * @version 2.1.0
 * @date 2025-06-27
 * @author Gemini Assistant
 */

// Wait for the DOM to be fully loaded before initializing the app
document.addEventListener('DOMContentLoaded', () => {

    // --- GLOBAL STATE & VARIABLES --- //
    let articleBlocks = [];
    let currentReferenceCode = null;
    let selectedArticleIndex = -1;
    let quillEditors = {};
    let currentQuillEditor = null;
    let currentPersonalizationType = 'none';
    let debounceTimeout = null;

    // --- DOM ELEMENT SELECTORS (Grouped for clarity) --- //
    const step1Section = document.getElementById('step1-section');
    const mainContainer = document.getElementById('mainContainer');
    const codeContainerWrapper = document.getElementById('codeContainerWrapper');
    const layoutForm = document.getElementById('layoutForm');
    const contentForm = document.getElementById('contentForm');
    const articleBlocksDiv = document.getElementById('articleBlocks');
    const htmlOutputTextarea = document.getElementById('htmlOutput');
    const livePreviewIframe = document.getElementById('live-preview-iframe');
    const moveUpBtn = document.getElementById('moveUpBtn');
    const moveDownBtn = document.getElementById('moveDownBtn');
    const removeBtn = document.getElementById('removeBtn');
    const personalizationButton = document.getElementById('personalizationButton');
    const personalizationDropdown = document.getElementById('personalizationDropdown');
    const personalizationTagList = document.getElementById('personalizationTagList');
    
    // --- AppConfig (Passed from PHP) --- //
    const AppConfig = window.AppConfig || {};


    // --- UTILITY FUNCTIONS --- //

    /**
     * A more robust helper for making API calls to the PHP backend.
     * It now includes better error handling for non-JSON responses.
     * @param {string} endpoint The PHP API endpoint (e.g., 'save_email.php').
     * @param {Object} data The data to send in the request body.
     * @returns {Promise<Object>} The JSON response from the API.
     */
    const apiCall = async (endpoint, data) => {
        const url = `${AppConfig.base_url}api/${endpoint}`;
        console.log('Constructed API URL:', url);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data),
            });

            const responseText = await response.text();

            if (!response.ok) {
                // The server responded with an error status (4xx or 5xx)
                // The responseText might contain the PHP error message (as HTML)
                 throw new Error(`HTTP error! status: ${response.status}, response: ${responseText}`);
            }

            try {
                // Try to parse the response text as JSON
                return JSON.parse(responseText);
            } catch (e) {
                // This catch block handles cases where the server returns a 200 OK status,
                // but the response body is not valid JSON (e.g., it's a PHP warning/notice as HTML).
                console.error("Failed to parse API response as JSON.", { endpoint, responseText });
                throw new Error(`Invalid JSON response from server: ${responseText}`);
            }

        } catch (error) {
            // This catches network errors or errors thrown from the blocks above.
            console.error('API call failed:', { endpoint, error });
            showModalMessage('API Error', `Could not connect to the server or the API failed. Please check the browser console for details. Error: ${error.message}`);
            throw error; // Re-throw to allow further error handling by the caller
        }
    };
    
    /**
     * Generates the final HTML for the email and updates the preview.
     * This now correctly calls the 'generate_html.php' endpoint.
     */
    const generateHtmlAndPreview = async () => {
        console.log("Generating HTML and updating preview...");
        const formData = getCurrentFormData();

        try {
            // This is the call that was failing with a 404 error.
            // It will now succeed because we are creating generate_html.php
            const response = await apiCall('generate_html.php', formData);
            
            if (response.success && response.data.html) {
                const generatedHtml = response.data.html;
                htmlOutputTextarea.value = generatedHtml;

                // Update live preview iframe safely
                if (livePreviewIframe && livePreviewIframe.contentWindow) {
                    const iframeDoc = livePreviewIframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(generatedHtml);
                    iframeDoc.close();
                }
            } else {
                const errorMessage = response.message || 'The API did not return any HTML content.';
                console.error('HTML generation failed:', errorMessage);
                showModalMessage('Generation Error', `Failed to generate HTML: ${errorMessage}`);
            }
        } catch (error) {
            console.error('Error in generateHtmlAndPreview:', error);
            // The apiCall function already shows a detailed modal message.
        }
    };


    /**
     * Handles the submission of the initial form in Step 1.
     * Corrected to call 'fetch_emails.php' as per your server file list.
     */
    const handleLayoutFormSubmit = async () => {
        const referenceCodeInput = document.getElementById('referenceCode');
        const referenceCode = referenceCodeInput.value.trim();
        let emailData = null;

        if (referenceCode) {
            showModalMessage('Loading Email', `Attempting to load email with code: ${referenceCode}`);
            try {
                // Corrected endpoint from 'fetch_email.php' to 'fetch_emails.php'
                const response = await apiCall('fetch_emails.php', { reference_code: referenceCode });
                
                if (response.success && response.data) {
                    emailData = response.data;
                    currentReferenceCode = emailData.reference_code;
                    showModalMessage('Email Loaded', `Email "${emailData.subject || emailData.reference_code}" loaded successfully!`);
                } else {
                    showModalMessage('Load Error', response.message || 'No data found. Creating a new email.');
                    currentReferenceCode = generateReferenceCode();
                }
            } catch (error) {
                console.error('Error fetching email:', error);
                // apiCall will show the modal. We'll just set up for a new email.
                currentReferenceCode = generateReferenceCode();
            } finally {
                // Using a timeout to ensure the loading message is readable before it closes.
                 setTimeout(() => hideModal(document.getElementById('messageModal')), 1500);
            }
        } else {
            currentReferenceCode = generateReferenceCode();
        }

        resetEditorState(emailData);
        populateForms(emailData);
        renderArticleBlocks();

        step1Section.style.display = 'none';
        mainContainer.style.display = 'flex';
        codeContainerWrapper.style.display = 'block';

        updatePersonalizationUI();
        generateHtmlAndPreview();
    };

    // ... (The rest of your main.js file remains largely the same) ...
    // Make sure to include all other functions like resetEditorState, populateForms,
    // renderArticleBlocks, addArticleBlock, etc. from your original main.js file.
    // The changes above are the most critical ones to fix the reported errors.
    
    // NOTE: This is a placeholder for the rest of your functions. 
    // You should copy the full content of your existing main.js here,
    // only replacing the functions I've provided above.
    function getCurrentFormData() {
        // Placeholder for your existing function
        const data = {
            reference_code: currentReferenceCode,
            subject: document.getElementById('subjectLine').value,
            preheader: document.getElementById('preheaderText').value,
            logo_url: document.getElementById('logoUrl').value,
            personalization_type: currentPersonalizationType,
            article_blocks: []
        };
        
        articleBlocks.forEach((block, index) => {
             const articleData = { type: block.type, title: block.title };
             const quillEditor = quillEditors[`editor-article-${index}-body`];
             articleData.body = quillEditor ? quillEditor.root.innerHTML : (block.body || '');
             data.article_blocks.push(articleData);
        });

        return data;
    }
    
    function showModalMessage(title, message) {
        // Placeholder for your existing modal function
        const modal = document.getElementById('messageModal');
        if (modal) {
            document.getElementById('messageModalTitle').textContent = title;
            document.getElementById('messageModalText').textContent = message;
            showModal(modal);
        } else {
            alert(`${title}: ${message}`);
        }
    }

    function showModal(modalElement) {
        if(modalElement) modalElement.style.display = 'block';
        const backdrop = document.getElementById('modalBackdrop');
        if(backdrop) backdrop.style.display = 'block';
    }

    function hideModal(modalElement) {
        if(modalElement) modalElement.style.display = 'none';
        const backdrop = document.getElementById('modalBackdrop');
        if(backdrop) backdrop.style.display = 'none';
    }

    function generateReferenceCode() {
        return Math.random().toString(36).substring(2, 7).toUpperCase();
    }
    
    function resetEditorState(data) { articleBlocks = data?.article_blocks || []; }
    function populateForms(data) { 
        document.getElementById('subjectLine').value = data?.subject || '';
        document.getElementById('preheaderText').value = data?.preheader || '';
    }
    function renderArticleBlocks() { /* ... your logic ... */ }
    function updatePersonalizationUI() { /* ... your logic ... */ }

    function initializeApp() {
        // Placeholder for your app initialization
        layoutForm.addEventListener('submit', (e) => {
            e.preventDefault();
            handleLayoutFormSubmit();
        });
        
        // Add more listeners here
        document.getElementById('addSingleColumnButton').addEventListener('click', () => addArticleBlock('single'));

        console.log('EmailPOS App Initialized.');
    }
    
    function addArticleBlock(type = 'single') {
        const newBlock = { id: `block-${Date.now()}`, type, title: 'New Article', body: '<p>Some default text.</p>' };
        articleBlocks.push(newBlock);
        renderArticleBlocks();
        generateHtmlAndPreview();
    }
    
    initializeApp();
});
```

### Action Plan

1.  **Create the new file**: Create a file named `generate_html.php` inside your `/api/` directory on the Azure server. Copy the content from the first code block above into this new file.
2.  **Update `main.js`**: Replace the content of your existing `assets/js/main.js` file with the updated code from the second block above. This new version has the corrected `apiCall` logic and targets the right `fetch_emails.php` endpoint.
3.  **Check Other API Files**: For the `SyntaxError` to be fully resolved, you must ensure that **every single file** in your `/api/` directory (like `fetch_emails.php`, `save_email.php`, etc.) starts with `require_once __DIR__ . '/../includes/init.php';` and uses the `send_json_response()` function to return data, especially in case of an error. They should never `echo` raw HTML or use `die()`.

For example, your `api/fetch_emails.php` should look something like this:

```php
<?php
// THIS IS THE MOST IMPORTANT PART
require_once __DIR__ . '/../includes/init.php';

// Get input
$data = json_decode(file_get_contents('php://input'), true);
$reference_code = $data['reference_code'] ?? null;

if (!$reference_code) {
    // Always use the helper function to send a JSON error
    send_json_response(false, 'Reference code is required.', null, 400);
    exit;
}

try {
    // Your database logic to fetch the email
    $stmt = $pdo->prepare("SELECT * FROM emails WHERE reference_code = ?");
    $stmt->execute([$reference_code]);
    $email = $stmt->fetch();

    if ($email) {
        // The data needs to be decoded if it's stored as JSON
        $email['article_blocks'] = json_decode($email['article_blocks_json'], true);
        send_json_response(true, 'Email fetched successfully.', $email);
    } else {
        send_json_response(false, 'Email not found.');
    }

} catch (PDOException $e) {
    // Log the real error for your own debugging
    error_log("Database error in fetch_emails.php: " . $e->getMessage());
    // Send a generic, safe error message to the client
    send_json_response(false, 'A database error occurred.', null, 500);
}
