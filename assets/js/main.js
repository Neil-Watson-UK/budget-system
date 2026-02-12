/**
 * main.js
 *
 * This file controls all the client-side interactivity of the email editor,
 * including DOM manipulation, event handling, AJAX calls to the PHP backend,
 * and live preview updates.
 *
 * @version 2.0.10 // Incremented version after major feature addition
 * @date 2025-06-27
 * @author Gemini Assistant
 */

// Define Quill.js global if not already defined and register custom blots/modules.
// This block must execute before any Quill instances are created.
if (typeof Quill !== 'undefined') {
    const Embed = Quill.import('blots/embed');
    const BlockEmbed = Quill.import('blots/block/embed'); // For block-level embeds if needed
    const Inline = Quill.import('blots/inline');
    const Container = Quill.import('blots/container');
    const Block = Quill.import('blots/block'); // ADDED: Import for Quill's Block blot


    // Custom Emoji Blot (to ensure emoji are handled consistently)
    class CustomEmojiBlot extends Embed {
        static blotName = 'emoji';
        static tagName = 'span'; // Or 'img' if using image-based emojis
        static className = 'emoji'; // For custom styling

        static create(value) {
            const node = super.create();
            node.innerHTML = value; // Store the emoji character
            return node;
        }

        static value(node) {
            return node.innerHTML;
        }
    }
    Quill.register(CustomEmojiBlot, true); // Registering with `true` to overwrite if already exists

    // Custom Quill module for handling a "translate" button in the toolbar
    const icons = Quill.import('ui/icons');
    if (icons) {
        icons['translate-icon'] = '<i class="fas fa-language"></i>'; // Font Awesome language icon
    }

    // Quill.js Custom Blots and Formats
    // These define custom HTML structures for the editor to ensure email client compatibility.

    // Custom block to preserve <p> tags with inline styles in email HTML
    class CustomParagraphBlot extends Block {
        static blotName = 'custom-paragraph';
        static tagName = 'p';
        static create(value) {
            let node = super.create();
            // Preserve any inline styles if they are passed as value
            if (typeof value === 'object' && value.style) {
                Object.assign(node.style, value.style);
            }
            return node;
        }
        static formats(node) {
            let formats = {};
            if (node.style.textAlign) formats['align'] = node.style.textAlign;
            return formats;
        }
    }

    // Custom blot for horizontal rule (HR)
    class DividerBlot extends Embed {
        static blotName = 'divider';
        static tagName = 'hr';
        static create() {
            let node = super.create();
            node.setAttribute('style', 'border: 0; border-top: 1px solid #cccccc; margin: 20px 0;');
            return node;
        }
    }

    // Custom blot for a button in email format
    class ButtonBlot extends Embed {
        static blotName = 'emailButton';
        static tagName = 'center'; // Wrap in center for Outlook compatibility
        static create(value) {
            let node = super.create();
            node.setAttribute('style', 'text-align: center;');

            const table = document.createElement('table');
            table.setAttribute('width', '60%');
            table.setAttribute('cellpadding', '0');
            table.setAttribute('cellspacing', '0');
            table.setAttribute('border', '0');
            table.setAttribute('style', 'width:60%;'); // Inline style for Outlook

            const tbody = document.createElement('tbody');
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.setAttribute('style', 'background-color:#00353d; padding:15px; margin:auto; line-height:1; text-align:center;');

            const p = document.createElement('p');
            p.setAttribute('style', 'margin: 0; text-align:center;');

            const a = document.createElement('a');
            a.setAttribute('href', value.url || 'https://example.com');
            a.setAttribute('style', 'color:#fff; text-decoration: none;');
            a.textContent = value.text || 'Your Button Text';
            a.innerHTML = `<span style="color:#ffffff;font-family:'EPOS BASIS', Arial, sans-serif !important;line-height: 1.5;">${a.textContent}</span>`;

            p.appendChild(a);
            td.appendChild(p);
            tr.appendChild(td);
            tbody.appendChild(tr);
            table.appendChild(tbody);
            node.appendChild(table);

            return node;
        }

        static value(node) {
            const a = node.querySelector('a');
            return {
                url: a ? a.href : 'https://example.com',
                text: a ? a.textContent : 'Your Button Text'
            };
        }
    }

    // Custom blot for a two-column layout in email format
    class TwoColumnBlot extends Container {
        static blotName = 'twoColumn';
        static tagName = 'table';

        static className = 'two-column-layout'; // Optional CSS class for responsiveness

        static create() {
            let node = super.create();
            node.setAttribute('width', '100%');
            node.setAttribute('cellpadding', '0');
            node.setAttribute('cellspacing', '0');
            node.setAttribute('border', '0');
            node.setAttribute('style', 'max-width: 600px; margin: 0 auto; background-color: #ffffff; overflow: hidden;');

            const outerTr = document.createElement('tr');
            const outerTd = document.createElement('td');
            outerTd.setAttribute('style', 'padding: 20px;');

            const innerTable = document.createElement('table');
            innerTable.setAttribute('width', '100%');
            innerTable.setAttribute('cellpadding', '0');
            innerTable.setAttribute('cellspacing', '0');
            innerTable.setAttribute('border', '0');

            const innerTr = document.createElement('tr');

            const leftCol = document.createElement('td');
            leftCol.classList.add('col');
            leftCol.setAttribute('width', '50%');
            leftCol.setAttribute('valign', 'top');
            leftCol.setAttribute('style', 'padding-right: 10px; vertical-align: top;');
            leftCol.innerHTML = '<p>Left column content goes here.</p>'; // Default content

            const rightCol = document.createElement('td');
            rightCol.classList.add('col');
            rightCol.setAttribute('width', '50%');
            rightCol.setAttribute('valign', 'top');
            rightCol.setAttribute('style', 'padding-left: 10px; vertical-align: top;');
            rightCol.innerHTML = '<p>Right column content goes here.</p>'; // Default content

            innerTr.appendChild(leftCol);
            innerTr.appendChild(rightCol);
            innerTable.appendChild(innerTr);
            outerTd.appendChild(innerTable);
            outerTr.appendChild(outerTd);
            node.appendChild(outerTr);

            return node;
        }

        // You might define formats or value methods if you want to allow changing properties
    }

    // Custom blot for personalization tags (e.g., {{FIRST_NAME}})
    class PersonalizationTagBlot extends Embed {
        static blotName = 'personalizationTag';
        static tagName = 'span'; // Or 'a' if they are clickable links for SFMC

        static create(value) {
            let node = super.create(value);
            node.setAttribute('contenteditable', 'false'); // Make it non-editable in Quill
            node.setAttribute('style', 'background-color: #e0f2f7; padding: 2px 5px; border-radius: 3px; cursor: pointer;'); // Visual styling
            node.textContent = value;
            return node;
        }

        static value(node) {
            return node.textContent;
        }
    }

    // Custom Image Blot for Email HTML - ensuring attributes crucial for email clients
    class EmailImageBlot extends Embed {
        static blotName = 'emailImage';
        static tagName = 'img';

        static create(value) {
            let node = super.create();
            node.setAttribute('src', value.src);
            node.setAttribute('alt', value.alt || '');
            node.setAttribute('style', 'max-width:100%; height:auto; display:block;'); // Important for responsiveness
            node.setAttribute('border', '0'); // Good for Outlook

            if (value.width) {
                node.setAttribute('width', value.width);
            }
            if (value.height) {
                // Only set height if explicitly given and not auto, often best to keep auto
                // For responsive images, it's generally better to rely on height:auto
                node.setAttribute('height', value.height);
            }
            return node;
        }

        static value(node) {
            return {
                src: node.getAttribute('src'),
                alt: node.getAttribute('alt'),
                width: node.getAttribute('width'),
                height: node.getAttribute('height')
            };
        }
    }


    // Registering Custom Blots
    Quill.register(CustomParagraphBlot);
    Quill.register(DividerBlot);
    Quill.register(ButtonBlot);
    Quill.register(TwoColumnBlot);
    Quill.register(PersonalizationTagBlot);
    Quill.register(EmailImageBlot, true); // Overwrite default image blot
}


// Wait for the DOM to be fully loaded before initializing the app
document.addEventListener('DOMContentLoaded', () => {

    // --- GLOBAL STATE & VARIABLES --- //
    let articleBlocks = []; // Array to store configuration of each article block
    let currentReferenceCode = null; // The reference code of the currently loaded email
    let selectedArticleIndex = -1; // Index of the currently selected article block (for move/remove actions)
    let quillEditors = {}; // Object to store Quill editor instances, keyed by their DOM element ID
    let currentQuillEditor = null; // Reference to the Quill editor instance currently in focus
    let currentPersonalizationType = 'none'; // Stores the selected personalization type from Step 1
    let debounceTimeout = null; // Timer for the debounce utility function


    // --- DOM ELEMENT SELECTORS --- //
    // Grouping all DOM element selections at the start for clarity and efficiency.
    // Step 1 Elements
    const step1Section = document.getElementById('step1-section');
    const layoutForm = document.getElementById('layoutForm');
    const referenceCodeInput = document.getElementById('referenceCode');
    const openSearchModalBtn = document.getElementById('openSearchModalBtn');
    const duplicateEmailBtn = document.getElementById('duplicateEmailBtn');
    const displayReferenceCodeSpan = document.getElementById('displayReferenceCode');
    const currentReferenceCodeDisplayDiv = document.getElementById('currentReferenceCodeDisplay');
    const createdByLabel = document.getElementById('createdByLabel');
    const createdByDisplaySpan = document.getElementById('createdByDisplay');
    const personalizationTypeRadios = document.querySelectorAll('input[name="personalizationType"]');


    // Step 2 Elements (Email Content Form)
    const mainContainer = document.getElementById('mainContainer'); // The main editor container
    const contentForm = document.getElementById('contentForm'); // The form containing general settings and article blocks
    const articleBlocksDiv = document.getElementById('articleBlocks'); // Container where dynamic article blocks are rendered
    const addSingleColumnButton = document.getElementById('addSingleColumnButton');
    const addDoubleColumnButton = document.getElementById('addDoubleColumnButton'); // This button might be missing in your HTML - adding it here for completeness
    const addSignOffButton = document.getElementById('addSignOffButton');
    const loadSavedBlockBtn = document.getElementById('loadSavedBlockBtn');
    const globalTranslateButton = document.getElementById('globalTranslateButton');
    const moveUpBtn = document.getElementById('moveUpBtn');
    const moveDownBtn = document.getElementById('moveDownBtn');
    const removeBtn = document.getElementById('removeBtn');

    // General Settings (Step 2)
    const subjectLineInput = document.getElementById('subjectLine');
    const preheaderTextInput = document.getElementById('preheaderText');
    const backgroundImageInput = document.getElementById('backgroundImage');
    const regionSelect = document.getElementById('region');
    const emailFromInput = document.getElementById('emailFrom');
    const senderNameInput = document.getElementById('senderName');
    const senderEmailInput = document.getElementById('senderEmail');
    const audienceSelect = document.getElementById('audience'); // Added from emailpos.php
    const sendTimeInput = document.getElementById('sendTime');   // Added from emailpos.php
    const logoUrlInput = document.getElementById('logoUrl');     // Added from emailpos.php
    const senderMobileInput = document.getElementById('senderMobile'); // Added from emailpos.php


    // Step 3 Elements (Code and Preview)
    const codeContainerWrapper = document.getElementById('codeContainerWrapper');
    const htmlOutputTextarea = document.getElementById('htmlOutput');
    const livePreviewIframe = document.getElementById('live-preview-iframe');
    const generateAndSaveEmailBtn = document.getElementById('generateAndSaveEmailBtn');
    const copyHtmlBtn = document.getElementById('copyHtmlBtn');
    const downloadHtmlBtn = document.getElementById('downloadHtmlBtn');
    const saveAsEmailBtn = document.getElementById('saveAsEmailBtn');
    const personalizationButton = document.getElementById('personalizationButton'); // Button to toggle personalization dropdown
    const personalizationDropdown = document.getElementById('personalizationDropdown'); // The personalization dropdown content
    const personalizationTagListInDropdown = document.getElementById('personalizationTagList'); // UL inside personalization dropdown
    const manageTagsBtn = document.getElementById('manageTagsBtn'); // Button to open personalization tag management modal
    const previewDesktopBtn = document.getElementById('preview-desktop');
    const previewMobileBtn = document.getElementById('preview-mobile');
    const testEmailRecipientInput = document.getElementById('testEmailRecipient');
    const sendTestEmailButton = document.getElementById('sendTestEmailButton');


    // Modals and Modal-specific Elements (from includes/modals.html)
    const modalBackdrop = document.getElementById('modalBackdrop');
    const searchEmailModal = document.getElementById('searchEmailModal');
    const imageModal = document.getElementById('imageModal'); // Corrected from imageSelectorModal
    const saveAsModal = document.getElementById('saveAsModal');
    const loadBlockModal = document.getElementById('loadBlockModal');
    const translateModal = document.getElementById('translateModal');
    const confirmationModal = document.getElementById('confirmModal'); // Corrected ID from modals.html
    const messageModal = document.getElementById('messageModal');
    const personalizationTagModal = document.getElementById('personalizationTagModal');

    // Confirmation Modal
    const confirmationModalTitle = document.getElementById('confirmModalTitle'); // Corrected ID
    const confirmationModalMessage = document.getElementById('confirmModalText'); // Corrected ID
    const confirmYesBtn = document.getElementById('confirmYesBtn');
    const confirmNoBtn = document.getElementById('confirmNoBtn');
    const confirmOkBtn = document.getElementById('confirmOkBtn');
    let confirmationCallback = null; // Stores the callback function for confirmation modal

    // Message Modal
    const messageModalTitle = document.getElementById('messageModalTitle');
    const messageModalText = document.getElementById('messageModalText');
    const messageModalOkBtn = document.getElementById('messageModalOkBtn'); // Added for the OK button in message modal

    // Search Email Modal
    const searchSubjectLineInput = document.getElementById('searchSubjectLine');
    const searchReferenceCodeInput = document.getElementById('searchReferenceCode');
    const searchAuthorInput = document.getElementById('searchAuthor');
    const searchSenderEmailInput = document.getElementById('searchSenderEmail');
    const searchRegionInput = document.getElementById('searchRegion');
    const performSearchBtn = document.getElementById('performSearchBtn'); // Button within search modal
    const searchResultsList = document.getElementById('searchResults'); // Corrected ID to match updated modals.html
    const noSearchResultsMessage = searchResultsList.querySelector('p') || document.createElement('p'); // "No emails found" message, create if not exists
    if(!noSearchResultsMessage.id) noSearchResultsMessage.id = 'noSearchResults'; // Give it an ID if it's new

    // Save As Modal
    const newReferenceCodeInput = document.getElementById('newReferenceCode');
    const newEmailTitleInput = document.getElementById('newEmailTitle');
    const confirmSaveAsBtn = document.getElementById('confirmSaveAsBtn');

    // Load Block Modal
    const blockSearchInput = document.getElementById('blockSearchInput');
    const performBlockSearchBtn = document.getElementById('performBlockSearchBtn');
    const savedBlocksList = document.getElementById('savedBlocksList'); // UL for saved blocks
    const noSavedBlocksMessage = document.getElementById('noSavedBlocks');

    // Translate Modal
    const originalTextarea = document.getElementById('originalText');
    const translatedTextarea = document.getElementById('translatedText');
    const targetLanguageSelect = document.getElementById('targetLanguage');
    const initiateTranslationBtn = document.getElementById('initiateTranslationBtn');
    const insertTranslatedTextBtn = document.getElementById('insertTranslatedTextBtn'); // Added this ID

    // Personalization Tag Management Modal
    const newTagInput = document.getElementById('newTagInput');
    const addTagBtn = document.getElementById('addTagBtn');
    const availableTagsList = document.getElementById('availableTagsList'); // UL for available tags

    // Image Selector Modal specific inputs (from the new modal structure)
    const imageFile = document.getElementById('imageFile');
    const imageDescription = document.getElementById('imageDescription');
    const imageTypeRadios = document.querySelectorAll('input[name="imageType"]');
    const customImageSizeGroup = document.getElementById('customImageSizeGroup');
    const customWidth = document.getElementById('customWidth');
    const customHeight = document.getElementById('customHeight');
    const uploadImageBtn = document.getElementById('uploadImageBtn');
    const imageLibrary = document.getElementById('imageLibrary');
    const imageLibrarySearch = document.getElementById('imageLibrarySearch');
    const insertSelectedImageBtn = document.getElementById('insertSelectedImageBtn');


    // --- AppConfig (Passed from PHP via emailpos.php) --- //
    const AppConfig = window.AppConfig || {};


    // --- UTILITY FUNCTIONS --- //

    /**
     * Helper for making authenticated API calls to the PHP backend.
     * @param {string} endpoint The PHP API endpoint (e.g., 'generate_html.php').
     * @param {Object} data The data to send in the request body (will be JSON encoded).
     * @param {boolean} [isFormData=false] - If true, data is FormData (for file uploads).
     * @returns {Promise<Object>} The JSON response from the API. Throws an error on non-2xx status.
     */
    const apiCall = async (endpoint, data, isFormData = false) => {
        try {
            // Normalize base_app_url to ensure it ends with a single slash
            const baseUrl = AppConfig.base_app_url.endsWith('/') ? AppConfig.base_app_url : `${AppConfig.base_app_url}/`;
            // Normalize endpoint to ensure it doesn't start with a slash, then prepend 'api/'
            let apiPath = `api/${endpoint.startsWith('/') ? endpoint.substring(1) : endpoint}`;

            const url = `${baseUrl}${apiPath}`;
            console.log('Constructed API URL:', url);

            const options = { method: 'POST' };

            if (isFormData) {
                options.body = data; // FormData object, browser sets Content-Type
            } else {
                options.headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                };
                options.body = JSON.stringify(data);
            }

            const response = await fetch(url, options);

            if (!response.ok) {
                const errorText = await response.text();
                let errorMessage = `HTTP error! status: ${response.status}`;
                try {
                    const errorJson = JSON.parse(errorText);
                    errorMessage += `, message: ${errorJson.error || errorJson.message || 'Unknown error'}`;
                } catch (e) {
                    errorMessage += `, response: ${errorText.substring(0, 100)}...`;
                }
                throw new Error(errorMessage);
            }

            return await response.json();
        } catch (error) {
            console.error('API call failed:', endpoint, error);
            showModalMessage('API Error', `Could not connect to the server or API failed: ${error.message}`);
            throw error;
        }
    };


    /**
     * Generates a unique 5-character alphanumeric reference code.
     * @returns {string} The unique reference code.
     */
    const generateReferenceCode = () => {
        const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let result = '';
        for (let i = 0; i < 5; i++) {
            result += characters.charAt(Math.floor(Math.random() * characters.length));
        }
        return result;
    };

    /**
     * Displays a modal and its backdrop.
     * @param {HTMLElement} modalElement The modal DOM element to show.
     */
    const showModal = (modalElement) => {
        if (!modalBackdrop || !modalElement) {
            console.error('Error: Modal backdrop or modal element not found for showModal.');
            return;
        }
        modalBackdrop.style.display = 'block';
        modalElement.style.display = 'block';
        document.body.classList.add('modal-open'); // Add class to body to prevent scrolling
    };

    /**
     * Hides a modal and its backdrop.
     * @param {HTMLElement} modalElement The modal DOM element to hide.
     */
    const hideModal = (modalElement) => {
        if (!modalElement) return;
        modalElement.style.display = 'none';
        // Only hide backdrop if no other modals are open
        const openModals = document.querySelectorAll('.app-modal[style*="display: block"]');
        if (openModals.length === 0 && modalBackdrop) {
            modalBackdrop.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
    };

    /**
     * Shows a confirmation modal with customizable buttons.
     * Overridden to also handle 'prompt_button' type for dynamic input.
     * @param {string} title - The title for the modal.
     * @param {string} message - The message content.
     * @param {string} [type='confirm'] - 'confirm' for Yes/No, 'alert' for OK only, 'prompt_button' for button input.
     * @returns {Promise<boolean|object>} - Resolves true/false for confirm/alert, or {confirmed: bool, url: string, text: string} for prompt_button.
     */
    const showConfirmModal = (title, message, type = 'confirm') => {
        return new Promise(resolve => {
            const modal = document.getElementById('confirmModal');
            const titleEl = document.getElementById('confirmModalTitle');
            const textEl = document.getElementById('confirmModalText');
            const yesBtn = document.getElementById('confirmYesBtn');
            const noBtn = document.getElementById('confirmNoBtn');
            const okBtn = document.getElementById('confirmOkBtn');
            const closeBtn = modal.querySelector('.close-button');
            const backdrop = document.getElementById('modalBackdrop');

            // Reset initial content and button states
            textEl.innerHTML = `<p>${message}</p>`; // Default message display
            yesBtn.textContent = 'Yes';
            noBtn.textContent = 'No';
            yesBtn.style.display = 'inline-block';
            noBtn.style.display = 'inline-block';
            okBtn.style.display = 'none';

            titleEl.textContent = title;

            if (type === 'alert') {
                yesBtn.style.display = 'none';
                noBtn.style.display = 'none';
                okBtn.style.display = 'inline-block';
            } else if (type === 'prompt_button') {
                textEl.innerHTML = `
                    <div class="form-group mb-4">
                        <label for="buttonUrl" class="block text-sm font-medium text-gray-700">Button URL:</label>
                        <input type="url" id="buttonUrl" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="https://example.com">
                    </div>
                    <div class="form-group">
                        <label for="buttonText" class="block text-sm font-medium text-gray-700">Button Text:</label>
                        <input type="text" id="buttonText" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" placeholder="Click Here">
                    </div>
                `;
                yesBtn.textContent = 'Insert';
                noBtn.textContent = 'Cancel';
                yesBtn.style.display = 'inline-block';
                noBtn.style.display = 'inline-block';
                okBtn.style.display = 'none';
            }

            backdrop.style.display = 'block';
            modal.style.display = 'block';
            modal.classList.add('modal-active'); // Add active class for animations

            const cleanup = () => {
                modal.classList.remove('modal-active');
                modal.style.display = 'none';
                backdrop.style.display = 'none';
                yesBtn.onclick = null;
                noBtn.onclick = null;
                okBtn.onclick = null;
                closeBtn.onclick = null;
            };

            yesBtn.onclick = () => {
                if (type === 'prompt_button') {
                    const url = document.getElementById('buttonUrl').value;
                    const text = document.getElementById('buttonText').value;
                    cleanup();
                    resolve({ confirmed: true, url: url, text: text });
                } else {
                    cleanup();
                    resolve(true);
                }
            };
            noBtn.onclick = () => {
                cleanup();
                resolve(false);
            };
            okBtn.onclick = () => {
                cleanup();
                resolve(true);
            };
            closeBtn.onclick = () => {
                cleanup();
                resolve(false);
            };
            backdrop.onclick = (event) => {
                if (event.target === backdrop) {
                    cleanup();
                    resolve(false);
                }
            };
        });
    };


    /**
     * Shows a general message modal.
     * @param {string} title - The title of the message.
     * @param {string} message - The message content.
     */
    const showModalMessage = (title, message) => {
        const modal = document.getElementById('messageModal');
        const titleEl = document.getElementById('messageModalTitle');
        const textEl = document.getElementById('messageModalText');
        const okBtn = document.getElementById('messageModalOkBtn');
        const closeBtn = modal.querySelector('.close-button');
        const backdrop = document.getElementById('modalBackdrop');

        if (!modal || !titleEl || !textEl || !okBtn) {
            console.error('Message modal elements not found, falling back to alert.');
            alert(`${title}: ${message}`);
            return;
        }
        titleEl.textContent = title;
        textEl.textContent = message;
        showModal(modal);

        // Ensure listeners are clean
        okBtn.onclick = null;
        closeBtn.onclick = null;
        backdrop.onclick = null;

        okBtn.onclick = () => hideModal(modal);
        closeBtn.onclick = () => hideModal(modal);
        backdrop.onclick = (event) => {
            if (event.target === backdrop) {
                hideModal(modal);
            }
        };
    };


    /**
     * Debounces a function call.
     * @param {Function} func The function to debounce.
     * @param {number} delay The delay in milliseconds.
     * @returns {Function} The debounced function.
     */
    const debounce = (func, delay) => {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    };

    /**
     * Escapes HTML entities in a string to prevent XSS.
     * @param {string} str The string to escape.
     * @returns {string} The escaped string.
     */
    const escapeHtml = (str) => {
        if (typeof str !== 'string') return ''; // Ensure it's a string
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    };


    // --- CORE APPLICATION LOGIC --- //

    /**
     * Handles the submission of the initial form in Step 1.
     * Loads an existing email or prepares the UI for creating a new one.
     */
    const handleLayoutFormSubmit = async () => {
        const referenceCode = referenceCodeInput.value.trim();
        let emailData = null; // Will store fetched email data

        if (referenceCode) {
            showModalMessage('Loading Email', 'Attempting to load email...');
            try {
                // Fetch email data from the backend using the new endpoint
                const response = await apiCall('fetch_emails.php', { loadCode: referenceCode }, false);
                if (!response.success || !response.emailContent) { // Check for emailContent instead of data
                    showModalMessage('Load Error', `Failed to load email: ${response.message || 'No data found for this reference code. Creating a new email instead.'}`);
                    currentReferenceCode = generateReferenceCode(); // Generate new code for a new email
                    emailData = null;
                } else {
                    emailData = response.emailMetadata; // Use emailMetadata for form population
                    emailData.emailContent = response.emailContent; // Store for Quill
                    currentReferenceCode = emailData.referenceCode; // Ensure currentReferenceCode is set from fetched data
                    showModalMessage('Email Loaded', `Email "${emailData.subjectLine || emailData.referenceCode}" loaded successfully!`);
                }
            } catch (error) {
                console.error('Error fetching email:', error);
                showModalMessage('Error', `An error occurred while fetching email: ${error.message}. Creating a new email instead.`);
                currentReferenceCode = generateReferenceCode(); // Initialize as new email on error
                    emailData = null;
            } finally {
                // messageModal is typically auto-hidden by showModalMessage when a new message is displayed
                // but if there's no new message, ensure it's hidden.
                // Replaced with a more robust check in showModalMessage for its auto-hide behavior.
            }
        } else {
            // If no reference code provided, start a new email
            currentReferenceCode = generateReferenceCode();
            emailData = null;
        }

        // Reset and populate the form with fetched data or defaults
        resetEditorState(emailData);
        populateForms(emailData);
        renderArticleBlocks(emailData ? emailData.emailContent : null); // Pass content for initial Quill population if it's the main editor
                                                            // For article blocks, content is passed individually during render.

        // Transition UI from Step 1 to Step 2
        step1Section.style.display = 'none';
        mainContainer.style.display = 'flex';
        codeContainerWrapper.style.display = 'block';

        updatePersonalizationUI();
        generateHtmlAndPreview(); // Generate initial preview after loading/creating
    };

    /**
     * Resets the editor's internal state, clearing existing data and Quill instances.
     * @param {object|null} initialData - The loaded email data, if any, to pre-fill.
     */
    const resetEditorState = (initialData = null) => {
        // Destroy existing Quill instances to prevent memory leaks
        Object.keys(quillEditors).forEach(id => {
            const editor = quillEditors[id];
            if (editor && typeof editor.destroy === 'function') { // Modern Quill destroy method
                editor.destroy();
            }
            delete quillEditors[id];
        });
        quillEditors = {}; // Clear the storage for Quill instances

        // Populate articleBlocks array based on initialData or start fresh
        if (initialData && initialData.article_blocks && Array.isArray(initialData.article_blocks)) {
            articleBlocks = JSON.parse(JSON.stringify(initialData.article_blocks)); // Deep copy to prevent mutation issues
        } else {
            articleBlocks = []; // Start with empty if no saved data or invalid data
            addArticleBlock('single'); // Add a default single column block if starting fresh
        }
        selectedArticleIndex = -1; // No block is selected initially

        // Set personalization type based on loaded data or default to 'none'
        currentPersonalizationType = initialData?.personalizationType || 'html_only'; // Default to html_only
        const radio = document.querySelector(`input[name="personalizationType"][value="${currentPersonalizationType}"]`);
        if (radio) radio.checked = true; // Update Step 1 radio button
    };

    /**
     * Populates the main form fields from saved data or with defaults.
     * @param {object|null} emailData - The loaded email data.
     */
    const populateForms = (emailData = null) => {
        referenceCodeInput.value = currentReferenceCode; // Always update Step 1 input
        displayReferenceCodeSpan.textContent = currentReferenceCode;
        currentReferenceCodeDisplayDiv.style.display = 'block';

        if(emailData?.createdBy) { // Changed from created_by to createdBy
            createdByDisplaySpan.textContent = emailData.createdBy;
            createdByLabel.style.display = 'inline';
        } else {
             createdByLabel.style.display = 'none';
        }

        // Set form values, using emailData or empty strings if null/undefined
        subjectLineInput.value = emailData?.subjectLine || ''; // Changed from subject
        preheaderTextInput.value = emailData?.preheaderText || ''; // Added from your emailpos.php
        backgroundImageInput.value = emailData?.backgroundImage || ''; // Added from your emailpos.php
        regionSelect.value = emailData?.region || 'UKI'; // Default to UKI
        emailFromInput.value = emailData?.fromEmail || ''; // Added from your emailpos.php
        senderNameInput.value = emailData?.senderName || '';
        senderEmailInput.value = emailData?.senderEmail || '';
        audienceSelect.value = emailData?.audience || 'channel'; // Added from your emailpos.php
        sendTimeInput.value = emailData?.sendTime || '';   // Added from your emailpos.php
        logoUrlInput.value = emailData?.logoUrl || AppConfig.default_logo_url; // Added from your emailpos.php
        senderMobileInput.value = emailData?.senderMobile || ''; // Added from your emailpos.php

        // Ensure personalization type radio is updated visually
        const radio = document.querySelector(`input[name="personalizationType"][value="${currentPersonalizationType}"]`);
        if (radio) radio.checked = true;
    };

    /**
     * Adds a new article block to the email.
     * @param {string} type - The type of article block ('single', 'double', 'sign_off').
     * @param {Object} [initialData={}] - Optional initial data for the block.
     */
    const addArticleBlock = (type = 'single', initialData = {}) => {
        const newBlock = {
            id: `article-block-${Date.now()}-${articleBlocks.length}`, // Unique ID for DOM element
            type: type,
            // Initialize all possible fields with default empty values or provided initialData
            title: initialData.title || '',
            body: initialData.body || '', // For single/sign_off
            image_url: initialData.image_url || '',
            cta_text: initialData.cta_text || '',
            cta_url: initialData.cta_url || '',
            left_body: initialData.left_body || '', // For double
            left_image_url: initialData.left_image_url || '',
            left_cta_text: initialData.left_cta_text || '',
            left_cta_url: initialData.left_cta_url || '',
            right_body: initialData.right_body || '', // For double
            right_image_url: initialData.right_image_url || '',
            right_cta_text: initialData.right_cta_text || '',
            right_cta_url: initialData.right_cta_url || '',
            personalization_type: initialData.personalization_type || 'none'
        };
        articleBlocks.push(newBlock);
        renderArticleBlocks(); // Re-render all blocks to include the new one
        selectArticleBlock(articleBlocks.length - 1); // Select the newly added block
        generateHtmlAndPreview(); // Update preview after adding
    };


    /**
     * Renders all article blocks based on the global `articleBlocks` array.
     */
    const renderArticleBlocks = () => {
        articleBlocksDiv.innerHTML = ''; // Clear existing blocks in DOM
        // Destroy existing Quill instances from previous render to prevent memory leaks
        Object.keys(quillEditors).forEach(editorId => {
            if (quillEditors[editorId] && typeof quillEditors[editorId].destroy === 'function') {
                quillEditors[editorId].destroy();
            }
        });
        quillEditors = {}; // Clear old Quill instances for re-initialization

        articleBlocks.forEach((block, index) => {
            const blockElement = document.createElement('div');
            blockElement.className = `article-block ${index === selectedArticleIndex ? 'selected' : ''}`;
            blockElement.id = block.id; // Use the block's unique ID for DOM
            blockElement.dataset.index = index; // Store its current index for lookup

            // Generate the inner HTML for the block based on its type and data
            blockElement.innerHTML = getBlockTemplate(block.type, index, block);

            articleBlocksDiv.appendChild(blockElement);

            // Initialize Quill editor(s) for the new block
            initializeQuillForBlock(block.type, index, block);

            // Attach event listeners specific to this block
            attachArticleBlockListeners(blockElement, index);

            // Add click listener to the block itself to select it
            blockElement.addEventListener('click', (e) => {
                // Only select if click target is not a button inside the block,
                // allowing button actions to be handled separately
                if (!e.target.closest('button')) {
                    selectArticleBlock(index);
                }
            });
        });

        updateMoveButtons(); // Update disabled states for move buttons (global and block-specific)
    };

    /**
     * Creates the HTML form fields for a given block type.
     * @param {string} type - The type of block ('single', 'double', 'sign_off').
     * @param {number} index - The current index of the block.
     * @param {object} data - The data for this specific block.
     * @returns {string} - The HTML string for the block's form.
     */
    const getBlockTemplate = (type, index, data) => {
        let html = `
            <h4>Block ${index + 1} (${type === 'single' ? 'Single Column' : (type === 'double' ? 'Two Column' : 'Sign-off')})</h4>
            <div class="block-controls">
                <button class="remove-block-btn remove-button" data-index="${index}"><i class="fas fa-trash-alt"></i> Remove</button>
                <button class="move-up-btn format-button" data-index="${index}" ${index === 0 ? 'disabled' : ''}><i class="fas fa-arrow-up"></i> Move Up</button>
                <button class="move-down-btn format-button" data-index="${index}" ${index === articleBlocks.length - 1 ? 'disabled' : ''}><i class="fas fa-arrow-down"></i> Move Down</button>
            </div>
        `;

        // Title field (common for single and double, not sign-off)
        if (type === 'single' || type === 'double') {
            html += `
                <div class="form-group">
                    <label for="article-${index}-title">Title</label>
                    <input type="text" id="article-${index}-title" value="${escapeHtml(data.title)}" placeholder="Enter article title"/>
                </div>
            `;
        }

        // Content and fields specific to 'single' column block
        if (type === 'single') {
            html += `
                <div class="form-group">
                    <label>Body Content</label>
                    <div id="editor-article-${index}-body" class="quill-editor-container"></div>
                </div>
                <div class="form-group">
                    <label for="article-${index}-image-url">Image URL</label>
                    <div class="input-with-button">
                        <input type="text" id="article-${index}-image-url" value="${escapeHtml(data.image_url)}" placeholder="Enter image URL or select from library"/>
                        <button type="button" class="select-image-button" data-target-input-id="article-${index}-image-url">Select Image</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="article-${index}-cta-text">Call to Action Text</label>
                    <input type="text" id="article-${index}-cta-text" value="${escapeHtml(data.cta_text)}" placeholder="e.g., Read More"/>
                </div>
                <div class="form-group">
                    <label for="article-${index}-cta-url">Call to Action URL</label>
                    <input type="text" id="article-${index}-cta-url" value="${escapeHtml(data.cta_url)}" placeholder="e.g., https://example.com/article"/>
                </div>
            `;
        }
        // Content and fields specific to 'double' column block
        else if (type === 'double') {
            html += `
                <div class="double-column-layout">
                    <div class="column-left">
                        <h4>Left Column</h4>
                        <div class="form-group">
                            <label>Content</label>
                            <div id="editor-article-${index}-left-body" class="quill-editor-container"></div>
                        </div>
                        <div class="form-group">
                            <label for="article-${index}-left-image-url">Image URL</label>
                            <div class="input-with-button">
                                <input type="text" id="article-${index}-left-image-url" value="${escapeHtml(data.left_image_url || '')}" placeholder="Enter image URL"/>
                                <button type="button" class="select-image-button" data-target-input-id="article-${index}-left-image-url">Select Image</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="article-${index}-left-cta-text">CTA Text</label>
                            <input type="text" id="article-${index}-left-cta-text" value="${escapeHtml(data.left_cta_text || '')}" placeholder="e.g., Learn More"/>
                        </div>
                        <div class="form-group">
                            <label for="article-${index}-left-cta-url">CTA URL</label>
                            <input type="text" id="article-${index}-left-cta-url" value="${escapeHtml(data.left_cta_url || '')}" placeholder="e.g., https://example.com/left"/>
                        </div>
                    </div>
                    <div class="column-right">
                        <h4>Right Column</h4>
                        <div class="form-group">
                            <label>Content</label>
                            <div id="editor-article-${index}-right-body" class="quill-editor-container"></div>
                        </div>
                        <div class="form-group">
                            <label for="article-${index}-right-image-url">Image URL</label>
                            <div class="input-with-button">
                                <input type="text" id="article-${index}-right-image-url" value="${escapeHtml(data.right_image_url || '')}" placeholder="Enter image URL"/>
                                <button type="button" class="select-image-button" data-target-input-id="article-${index}-right-image-url">Select Image</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="article-${index}-right-cta-text">CTA Text</label>
                            <input type="text" id="article-${index}-right-cta-text" value="${escapeHtml(data.right_cta_text || '')}" placeholder="e.g., Discover More"/>
                        </div>
                        <div class="form-group">
                            <label for="article-${index}-right-cta-url">CTA URL</label>
                            <input type="text" id="article-${index}-right-cta-url" value="${escapeHtml(data.right_cta_url || '')}" placeholder="e.g., https://example.com/right"/>
                        </div>
                    </div>
                </div>
            `;
        }
        // Content field for 'sign_off' block
        else if (type === 'sign_off') {
            html += `
                <div class="form-group">
                    <label>Sign-off Content</label>
                    <div id="editor-article-${index}-body" class="quill-editor-container"></div>
                </div>
            `;
        }

        // Personalization type radio buttons (common to all blocks)
        html += `
            <div class="form-group">
                <label>Personalization Type</label>
                <div class="radio-group-personalization">
                    <label class="radio-label">
                        <input type="radio" name="personalization-type-${index}" value="none" ${data.personalization_type === 'none' ? 'checked' : ''}>
                        <span class="radio-icon fas fa-ban"></span> None
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="personalization-type-${index}" value="country" ${data.personalization_type === 'country' ? 'checked' : ''}>
                        <span class="radio-icon fas fa-globe"></span> Country
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="personalization-type-${index}" value="industry" ${data.personalization_type === 'industry' ? 'checked' : ''}>
                        <span class="radio-icon fas fa-industry"></span> Industry
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="personalization-type-${index}" value="company_size" ${data.personalization_type === 'company_size' ? 'checked' : ''}>
                        <span class="radio-icon fas fa-building"></span> Company Size
                    </label>
                </div>
            </div>
        `;

        return html;
    };


    /**
     * Initializes Quill.js editor(s) for a newly rendered block based on its type.
     * @param {string} type - The block type.
     * @param {number} index - The index of the block.
     * @param {object} data - The data for the block.
     */
    const initializeQuillForBlock = (type, index, data) => {
        const createQuillInstance = (editorId, initialHtmlContent) => {
            const editorElement = document.getElementById(editorId);
            if (!editorElement) {
                console.warn(`Quill editor element #${editorId} not found. Skipping initialization.`);
                return null;
            }

            // Return existing instance if already initialized to prevent duplicates
            if (quillEditors[editorId]) {
                return quillEditors[editorId];
            }

            const toolbarOptions = [
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'header': [1, 2, 3, false] }],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'indent': '-1'}, { 'indent': '+1' }],
                // Use AppConfig for colors
                [{ 'color': AppConfig.allowed_text_colors }, { 'background': AppConfig.allowed_bg_colors }],
                [{ 'font': ['EPOS-BASIS', 'Arial', 'Verdana', 'Times-New-Roman', 'Courier-New', 'Georgia', 'Trebuchet-MS', 'Impact'] }], // Add common fonts and custom font
                [{ 'align': [] }],
                ['link', 'image'], // Quill's default image button (we'll override its handler)
                ['clean'],
                ['emoji'], // Custom emoji button (requires quill-emoji.js)
                ['translate-icon'] // Custom translate button (requires custom handler and FA icon)
            ];

            const quill = new Quill(`#${editorId}`, {
                modules: {
                    toolbar: {
                        container: toolbarOptions,
                        handlers: {
                            'image': function() { // Custom handler for Quill's built-in image button
                                const range = this.quill.getSelection(true);
                                if (range) {
                                    currentQuillEditor = this.quill; // Set current editor
                                    imageModal.dataset.targetQuillEditorId = editorId; // Store which Quill editor to update
                                    imageModal.dataset.targetInputId = ''; // Clear direct input target
                                    // Make sure selectedImageUrlInput is cleared and image gallery section is visible/up-to-date
                                    if (imageFile) imageFile.value = ''; // Clear file input
                                    if (imageDescription) imageDescription.value = ''; // Clear description
                                    document.querySelector('input[name="imageType"][value="main_article"]').checked = true; // Reset radio
                                    customImageSizeGroup.style.display = 'none'; // Hide custom fields
                                    showModal(imageModal);
                                }
                            },
                            'translate-icon': function() { // Custom handler for translate button
                                currentQuillEditor = this.quill; // Set current editor
                                originalTextarea.value = currentQuillEditor.getText(); // Pre-fill with current editor's text
                                translatedTextarea.value = ''; // Clear translated text area
                                showModal(translateModal);
                            }
                        }
                    },
                    // Ensure these emoji modules are properly loaded via CDN in emailpos.php
                    'emoji-toolbar': true,
                    'emoji-textarea': true,
                    'emoji-shortname': true,
                    keyboard: { // Custom keyboard bindings (e.g., for 'Enter' key behavior)
                        bindings: {
                            enter: {
                                key: 13,
                                collapsed: true,
                                handler: function(range, context) {
                                    this.quill.insertText(range.index, '\n'); // Insert a new line
                                    this.quill.setSelection(range.index + 1); // Move cursor to the new line
                                    return false; // Prevent Quill's default enter key behavior
                                }
                            }
                        }
                    }
                },
                theme: 'snow'
            });

            if (initialHtmlContent) {
                // Set content using Quill's API, which handles HTML parsing safely
                quill.clipboard.dangerouslyPasteHTML(0, initialHtmlContent);
            }

            // Event listeners for Quill content changes and selection changes
            quill.on('text-change', debounce(generateHtmlAndPreview, 500)); // Debounce preview updates
            quill.on('selection-change', (range) => {
                if (range) {
                    currentQuillEditor = quill; // Update the globally tracked active editor
                }
            });
            quillEditors[editorId] = quill; // Store the Quill instance
            return quill;
        };

        // Initialize Quill instances based on block type
        if (type === 'single' || type === 'sign_off') {
            createQuillInstance(`editor-article-${index}-body`, data.body);
        } else if (type === 'double') {
            createQuillInstance(`editor-article-${index}-left-body`, data.left_body || '');
            createQuillInstance(`editor-article-${index}-right-body`, data.right_body || '');
        }
    };

    /**
     * Attaches event listeners for block-specific actions (remove, move, input changes, image select).
     * @param {HTMLElement} blockElement - The root DOM element of the article block.
     * @param {number} index - The current index of the article block in the array.
     */
    const attachArticleBlockListeners = (blockElement, index) => {
        // Input change listeners for text fields within the block
        blockElement.querySelectorAll('input[type="text"], input[type="url"]').forEach(input => {
            input.addEventListener('input', debounce(() => {
                const idParts = input.id.split('-'); // e.g., ['article', '0', 'title']
                if (idParts.length >= 3) {
                    const fieldType = idParts[2]; // 'title', 'image', 'cta'
                    const columnType = idParts.length > 3 ? idParts[2] : ''; // 'left', 'right' for double column
                    const actualField = idParts.length > 3 ? idParts[3] : idParts[2]; // 'image-url', 'cta-text'

                    if (actualField === 'title') {
                        articleBlocks[index].title = input.value;
                    } else if (actualField.includes('image-url')) {
                        if (columnType === 'left') articleBlocks[index].left_image_url = input.value;
                        else if (columnType === 'right') articleBlocks[index].right_image_url = input.value;
                        else articleBlocks[index].image_url = input.value;
                    } else if (actualField.includes('cta-text')) {
                        if (columnType === 'left') articleBlocks[index].left_cta_text = input.value;
                        else if (columnType === 'right') articleBlocks[index].right_cta_text = input.value;
                        else articleBlocks[index].cta_text = input.value;
                    } else if (actualField.includes('cta-url')) {
                        if (columnType === 'left') articleBlocks[index].left_cta_url = input.value;
                        else if (columnType === 'right') articleBlocks[index].right_cta_url = input.value;
                        else articleBlocks[index].cta_url = input.value;
                    }
                    generateHtmlAndPreview(); // Update preview on input change
                }
            }, 300)); // Debounce input updates
        });

        // Radio button change listeners for block-specific personalization type
        blockElement.querySelectorAll(`input[name="personalization-type-${index}"]`).forEach(radio => {
            radio.addEventListener('change', () => {
                articleBlocks[index].personalization_type = radio.value;
                generateHtmlAndPreview(); // Update preview when personalization type changes
            });
        });

        // Remove button for the specific block
        const removeButton = blockElement.querySelector('.remove-block-btn');
        if (removeButton) {
            removeButton.addEventListener('click', () => removeArticleBlock(index));
        }

        // Move Up button for the specific block
        const moveUpButton = blockElement.querySelector('.move-up-btn');
        if (moveUpButton) {
            moveUpButton.addEventListener('click', () => moveArticleBlock(index, -1));
        }

        // Move Down button for the specific block
        const moveDownButton = blockElement.querySelector('.move-down-btn');
        if (moveDownButton) {
            moveDownButton.addEventListener('click', () => moveArticleBlock(index, 1));
        }

        // Select Image button (for direct input fields, not Quill's image button)
        blockElement.querySelectorAll('button.select-image-button').forEach(button => {
            button.addEventListener('click', (event) => {
                const targetInputId = event.target.dataset.targetInputId;
                imageModal.dataset.targetInputId = targetInputId; // Store which input to update
                imageModal.dataset.targetQuillEditorId = ''; // Clear Quill editor target
                // Make sure file input and description are cleared, and pre-fill selectedImageUrlInput
                if (imageFile) imageFile.value = '';
                if (imageDescription) imageDescription.value = '';
                document.querySelector('input[name="imageType"][value="main_article"]').checked = true; // Reset radio
                customImageSizeGroup.style.display = 'none'; // Hide custom fields
                showModal(imageModal); // Open the image selector modal
            });
        });
    };

    /**
     * Selects an article block and updates its 'selected' class and global move/remove buttons.
     * @param {number} index - The index of the article block to select.
     */
    const selectArticleBlock = (index) => {
        if (selectedArticleIndex !== -1 && articleBlocks[selectedArticleIndex]) {
            const prevSelectedElement = document.getElementById(articleBlocks[selectedArticleIndex].id);
            if (prevSelectedElement) {
                prevSelectedElement.classList.remove('selected');
            }
        }
        selectedArticleIndex = index;
        if (selectedArticleIndex !== -1 && articleBlocks[selectedArticleIndex]) {
            const currentSelectedElement = document.getElementById(articleBlocks[selectedArticleIndex].id);
            if (currentSelectedElement) {
                currentSelectedElement.classList.add('selected');
                currentSelectedElement.scrollIntoView({ behavior: 'smooth', block: 'center' }); // Scroll to selected block
            }
        }
        updateMoveButtons(); // Update disabled states for global move/remove buttons
    };


    /**
     * Removes an article block from the `articleBlocks` array and re-renders.
     * @param {number} indexToRemove The index of the block to remove. Defaults to `selectedArticleIndex`.
     */
    const removeArticleBlock = (indexToRemove = selectedArticleIndex) => {
        if (indexToRemove === -1 || !articleBlocks[indexToRemove]) {
            showModalMessage('No Block Selected', 'Please select an article block to remove using the individual block "Remove" button or the global "Remove" button.');
            return;
        }

        showConfirmModal(
            'Confirm Removal',
            'Are you sure you want to remove this article block? This cannot be undone.',
            'confirm'
        ).then(confirmed => {
            if (confirmed) {
                // Remove Quill instances associated with this block before removing from array
                const blockToRemove = articleBlocks[indexToRemove];
                if (blockToRemove.type === 'single' || blockToRemove.type === 'sign_off') {
                    if (quillEditors[`editor-article-${indexToRemove}-body`]) {
                        quillEditors[`editor-article-${indexToRemove}-body`].destroy();
                        delete quillEditors[`editor-article-${indexToRemove}-body`];
                    }
                } else if (blockToRemove.type === 'double') {
                    if (quillEditors[`editor-article-${indexToRemove}-left-body`]) {
                        quillEditors[`editor-article-${indexToRemove}-left-body`].destroy();
                        delete quillEditors[`editor-article-${indexToRemove}-left-body`];
                    }
                    if (quillEditors[`editor-article-${indexToRemove}-right-body`]) {
                        quillEditors[`editor-article-${indexToRemove}-right-body`].destroy();
                        delete quillEditors[`editor-article-${indexToRemove}-right-body`];
                    }
                }

                articleBlocks.splice(indexToRemove, 1); // Remove the block from the array
                selectedArticleIndex = -1; // Deselect after removal
                renderArticleBlocks(); // Re-render remaining blocks to update indices and DOM
                generateHtmlAndPreview(); // Update preview
                showModalMessage('Block Removed', 'Article block removed successfully.');
            }
        });
    };

    /**
     * Moves an article block up or down in the `articleBlocks` array and re-renders.
     * @param {number} index The current index of the block to move.
     * @param {number} direction -1 for up, 1 for down.
     */
    const moveArticleBlock = (index, direction) => {
        const newIndex = index + direction;
        if (newIndex >= 0 && newIndex < articleBlocks.length) {
            // Swap elements in the array
            [articleBlocks[index], articleBlocks[newIndex]] = [articleBlocks[newIndex], articleBlocks[index]];

            renderArticleBlocks(); // Re-render to update DOM order and button states
            selectArticleBlock(newIndex); // Keep the moved block selected
            generateHtmlAndPreview(); // Update preview
        }
        updateMoveButtons(); // Re-evaluate disabled state after move
    };

    /**
     * Updates the disabled state of both block-specific and global move/remove buttons.
     */
    const updateMoveButtons = () => {
        // Update individual block buttons
        articleBlocks.forEach((block, index) => {
            const blockElement = document.getElementById(block.id);
            if (blockElement) {
                const moveUpButton = blockElement.querySelector('.move-up-btn');
                const moveDownButton = blockElement.querySelector('.move-down-btn');
                if (moveUpButton) {
                    moveUpButton.disabled = index === 0;
                }
                if (moveDownButton) {
                    moveDownButton.disabled = index === articleBlocks.length - 1;
                }
            }
        });
        // Update global move/remove buttons
        if (moveUpBtn) moveUpBtn.disabled = selectedArticleIndex <= 0;
        if (moveDownBtn) moveDownBtn.disabled = selectedArticleIndex === articleBlocks.length - 1 || selectedArticleIndex === -1;
        if (removeBtn) removeBtn.disabled = selectedArticleIndex === -1;
    };

    /**
     * Gathers all relevant form data from the editor, including general settings and article block content.
     * @returns {Object} An object containing all current email data, structured for the backend.
     */
    const getCurrentFormData = () => {
        const data = {
            referenceCode: currentReferenceCode, // Changed from reference_code
            subjectLine: subjectLineInput.value, // Changed from subject
            preheaderText: preheaderTextInput.value, // Added
            backgroundImage: backgroundImageInput.value, // Added
            region: regionSelect.value,
            fromEmail: emailFromInput.value, // Added
            senderName: senderNameInput.value,
            senderEmail: senderEmailInput.value,
            personalizationType: currentPersonalizationType, // Changed from personalization_type
            createdBy: AppConfig.logged_in_user_name, // User who is logged in and creating/saving (Changed from created_by)
            userId: AppConfig.logged_in_user_id, // Added for backend
            userLevel: AppConfig.logged_in_user_level, // Added for backend
            audience: audienceSelect.value, // Added
            sendTime: sendTimeInput.value, // Added, assuming 'send_time' from backend
            logoUrl: logoUrlInput.value, // Added
            senderMobile: senderMobileInput.value, // Added
            article_blocks: []
        };

        articleBlocks.forEach((block, index) => {
            let articleData = {
                type: block.type,
                personalization_type: block.personalization_type
            };

            if (block.type === 'single' || block.type === 'double') {
                articleData.title = block.title;
            }

            if (block.type === 'single' || block.type === 'sign_off') {
                const quillEditor = quillEditors[`editor-article-${index}-body`];
                articleData.body = quillEditor ? quillEditor.root.innerHTML : block.body; // Use Quill content if editor exists, else fallback
            }

            if (block.type === 'single') {
                articleData.image_url = block.image_url;
                articleData.cta_text = block.cta_text;
                articleData.cta_url = block.cta_url;
            } else if (block.type === 'double') {
                const leftQuill = quillEditors[`editor-article-${index}-left-body`];
                articleData.left_body = leftQuill ? leftQuill.root.innerHTML : block.left_body;
                articleData.left_image_url = block.left_image_url;
                articleData.left_cta_text = block.left_cta_text;
                articleData.left_cta_url = block.left_cta_url;

                const rightQuill = quillEditors[`editor-article-${index}-right-body`];
                articleData.right_body = rightQuill ? rightQuill.root.innerHTML : block.right_body;
                articleData.right_image_url = block.right_image_url;
                articleData.right_cta_text = block.right_cta_text;
                articleData.right_cta_url = block.right_cta_url;
            }
            data.article_blocks.push(articleData);
        });

        return data;
    };

    /**
     * Generates the final HTML for the email and updates the preview iframe and textarea.
     */
    const generateHtmlAndPreview = async () => {
        console.log("Generating HTML and updating preview...");
        const formData = getCurrentFormData();

        try {
            const response = await apiCall('generate_html.php', formData);
            if (response.success) {
                const generatedHtml = response.finalHtml; // Changed from html to finalHtml
                htmlOutputTextarea.value = generatedHtml;

                // Update live preview iframe
                const iframeDoc = livePreviewIframe.contentWindow.document;
                iframeDoc.open();
                iframeDoc.write(generatedHtml);
                iframeDoc.close();
            } else {
                console.error('HTML generation failed:', response.message);
                showModalMessage('Generation Error', `Failed to generate HTML: ${response.message}`);
            }
        } catch (error) {
            console.error('Error generating HTML:', error);
            showModalMessage('Generation Error', `An unexpected error occurred during HTML generation: ${error.message}`);
        }
    };

    /**
     * Handles the search for existing emails via the search modal.
     */
    const performEmailSearch = async () => {
        showModalMessage('Searching...', 'Please wait while we search for emails.');
        const searchParams = {
            subjectLine: searchSubjectLineInput.value.trim(),
            referenceCode: searchReferenceCodeInput.value.trim(),
            author: searchAuthorInput.value.trim(),
            senderEmail: searchSenderEmailInput.value.trim(),
            region: searchRegionInput.value.trim()
        };

        try {
            const results = await apiCall('search_emails.php', searchParams); // Uses POST by default now
            hideModal(messageModal); // Close the "Searching..." message
            displaySearchResults(results.emails); // Accessing .emails property as per fetch_emails.php structure
        } catch (error) {
            console.error('Email search failed:', error);
            // apiCall already shows a modal message for errors
        }
    };

    /**
     * Displays the search results in the search modal's table.
     * @param {Array<Object>} results An array of email metadata objects.
     */
    const displaySearchResults = (results) => {
        searchResultsList.innerHTML = ''; // Clear previous results

        if (results.length === 0) {
            noSearchResultsMessage.style.display = 'block';
            noSearchResultsMessage.textContent = 'No emails found matching your criteria.';
            return;
        }
        noSearchResultsMessage.style.display = 'none';

        // Re-construct the table if needed (or assume it's always there in modals.html)
        // For now, assuming it's structured for direct population as div elements
        results.forEach(email => {
            const emailEntry = document.createElement('div');
            emailEntry.classList.add('email-list-item', 'p-2', 'border', 'rounded', 'mb-2', 'cursor-pointer', 'hover:bg-gray-100');
            emailEntry.innerHTML = `
                <p><strong>Subject:</strong> ${email.subjectLine || 'N/A'}</p>
                <p><strong>Ref Code:</strong> ${email.referenceCode || 'N/A'}</p>
                <p><strong>Author:</strong> ${email.createdBy || 'N/A'}</p>
                <p><strong>Sent:</strong> ${email.sendTime || 'N/A'}</p>
                <p><strong>Region:</strong> ${email.region || 'N/A'}</p>
                <p><strong>Sender:</strong> ${email.senderEmail || 'N/A'}</p>
            `;
            emailEntry.onclick = () => {
                showConfirmModal(
                    'Load Email',
                    `Load email "${email.referenceCode}"? Unsaved changes will be lost.`,
                    'confirm'
                ).then(confirmed => {
                    if (confirmed) {
                        showModalMessage('Loading Email', `Loading email ${email.referenceCode}...`);
                        hideModal(searchEmailModal); // Close the search modal immediately
                        loadEmail(email.referenceCode); // Call the load function
                    }
                });
            };
            searchResultsList.appendChild(emailEntry);
        });
    };

    /**
     * Loads an email into the editor from its reference code.
     * @param {string} referenceCode The reference code of the email to load.
     */
    const loadEmail = async (referenceCode) => {
        try {
            const response = await apiCall('fetch_emails.php', { loadCode: referenceCode }); // Will use fetch_emails.php
            if (response.success && response.emailContent) {
                const emailData = response.emailMetadata;
                emailData.emailContent = response.emailContent; // Store the actual HTML content

                currentReferenceCode = emailData.referenceCode;

                // Update form fields in Step 1 and Step 2
                populateForms(emailData); // Populates all general settings
                resetEditorState(emailData); // This will clear existing and re-populate articleBlocks array, and set Quill content
                                             // based on emailData.emailContent passed from fetch_emails.php
                // Note: There is no single "main Quill editor" for the overall email content anymore.
                // The content is distributed among article blocks.
                // This line was likely from an earlier iteration. It's safe to remove if it doesn't serve a purpose.
                // loadHtmlIntoEditor(emailData.emailContent); // REMOVED as it's not applicable with articleBlocks

                // Show main editor UI
                step1Section.style.display = 'none';
                mainContainer.style.display = 'flex';
                codeContainerWrapper.style.display = 'block';

                showModalMessage('Email Loaded', `Email "${emailData.subjectLine || emailData.referenceCode}" loaded successfully!`);
                generateHtmlAndPreview(); // Update preview after loading
            } else {
                showModalMessage('Load Error', `Failed to load email: ${response.message || 'No data found.'}`);
            }
        } catch (error) {
            console.error('Error loading email:', error);
            showModalMessage('Load Error', `An unexpected error occurred while loading email: ${error.message}`);
        } finally {
            hideModal(messageModal); // Ensure loading message is hidden
        }
    };

    /**
     * Confirms and deletes an email from the backend.
     * @param {string} referenceCode The reference code of the email to delete.
     */
    const confirmDeleteEmail = (referenceCode) => {
        showConfirmModal(
            'Confirm Delete',
            `Are you sure you want to delete email with reference code: ${referenceCode}? This action cannot be undone.`,
            'confirm'
        ).then(async confirmed => {
            if (confirmed) {
                try {
                    const response = await apiCall('delete_email.php', { referenceCode: referenceCode }); // Changed to referenceCode
                    if (response.success) {
                        showModalMessage('Deleted!', `Email ${referenceCode} deleted successfully.`);
                        // Refresh search results if the modal is open
                        if (searchEmailModal.style.display === 'block') {
                            performEmailSearch(); // Re-run search
                        }
                        // If the deleted email was currently loaded, clear the editor
                        if (currentReferenceCode === referenceCode) {
                            resetEditor(); // Resets to a new empty email
                        }
                    } else {
                        showModalMessage('Delete Error', `Failed to delete email: ${response.message}`);
                    }
                } catch (error) {
                    console.error('Error deleting email:', error);
                    showModalMessage('Delete Error', `An unexpected error occurred while deleting: ${error.message}`);
                }
            }
        });
    };

    /**
     * Resets the entire editor UI to a clean, new email state.
     */
    const resetEditor = () => {
        currentReferenceCode = generateReferenceCode(); // Generate new code for a fresh start
        referenceCodeInput.value = currentReferenceCode;
        subjectLineInput.value = '';
        preheaderTextInput.value = '';
        backgroundImageInput.value = '';
        regionSelect.value = 'UKI';
        emailFromInput.value = '';
        senderNameInput.value = '';
        senderEmailInput.value = '';
        audienceSelect.value = 'channel'; // Reset
        sendTimeInput.value = ''; // Reset
        logoUrlInput.value = AppConfig.default_logo_url; // Reset
        senderMobileInput.value = ''; // Reset


        // Reset personalization radio to 'html_only' and update UI
        currentPersonalizationType = 'html_only';
        const noneRadio = document.querySelector('input[name="personalizationType"][value="html_only"]');
        if (noneRadio) noneRadio.checked = true;
        updatePersonalizationUI();

        // Reset article blocks and Quill editors
        articleBlocks = [];
        quillEditors = {};
        renderArticleBlocks(); // Renders the default single block due to resetEditorState's call to addArticleBlock
        generateHtmlAndPreview(); // Update preview to a default empty email
        showModalMessage('Editor Cleared', 'The editor has been reset to a new email.');
    };

    /**
     * Handles the "Save As" functionality, saving the current email as a new copy with a new reference code.
     */
    const handleSaveAsEmail = async () => {
        const newRefCode = newReferenceCodeInput.value.trim();
        const newTitle = newEmailTitleInput.value.trim();

        if (!newRefCode) {
            showModalMessage('Input Required', 'Please enter a new reference code.');
            return;
        }
        if (!newTitle) {
            showModalMessage('Input Required', 'Please enter a title for the new email.');
            return;
        }

        // Check if the new reference code already exists
        try {
            const checkResponse = await apiCall('check_reference_code.php', { referenceCode: newRefCode }); // Changed to referenceCode
            if (checkResponse.exists) {
                showModalMessage('Duplicate Reference', `The reference code "${newRefCode}" already exists. Please choose a different one.`);
                return;
            }
        } catch (error) {
            console.error('Error checking reference code:', error);
            showModalMessage('Error', `Failed to check reference code: ${error.message}`);
            return;
        }

        const formData = getCurrentFormData();
        formData.referenceCode = newRefCode; // Override with new ref code for saving
        formData.subjectLine = newTitle; // Override with new title for saving (Changed from subject)
        // The `createdBy` field is already correctly set in `getCurrentFormData` for the current user

        try {
            const response = await apiCall('save_email.php', formData);
            if (response.success) {
                showModalMessage('Success', `Email saved as new copy: ${newRefCode}`);
                currentReferenceCode = newRefCode; // Update editor to the new copy
                populateForms(formData); // Update main form fields to reflect the new copy's data
                hideModal(saveAsModal);
                generateHtmlAndPreview(); // Refresh preview with new email data
            } else {
                showModalMessage('Error', `Failed to save email as new copy: ${response.message}`);
            }
        } catch (error) {
            console.error('Error saving email as new copy:', error);
            showModalMessage('Error', `An unexpected error occurred while saving: ${error.message}`);
        }
    };


    /**
     * Handles search for reusable content blocks in the Load Block modal.
     */
    const handleBlockSearch = async () => {
        const searchTerm = blockSearchInput.value.trim();
        try {
            const response = await apiCall('fetch_reusable_blocks.php', { searchTerm: searchTerm }); // Assuming this is the correct endpoint and parameter
            if (response.success) {
                displaySavedBlocks(response.data);
            } else {
                showModalMessage('Search Error', `Failed to search blocks: ${response.message}`);
            }
        } catch (error) {
            console.error('Error searching blocks:', error);
            showModalMessage('Search Error', `An unexpected error occurred while searching blocks: ${error.message}`);
        }
    };

    /**
     * Displays saved blocks in the load block modal's list.
     * @param {Array<Object>} blocks - An array of saved block objects.
     */
    const displaySavedBlocks = (blocks) => {
        savedBlocksList.innerHTML = ''; // Clear previous results

        if (blocks.length === 0) {
            noSavedBlocksMessage.style.display = 'block';
            noSavedBlocksMessage.textContent = 'No saved blocks found.'; // Ensure message content is set
            return;
        }
        noSavedBlocksMessage.style.display = 'none';

        blocks.forEach(block => {
            const listItem = document.createElement('li');
            listItem.innerHTML = `
                <span class="block-name">${escapeHtml(block.block_name)}</span>
                <span class="block-type">(${escapeHtml(block.block_type)})</span>
                <div class="actions">
                    <button class="action-button load-block-item-btn" data-block-id="${block.id}">Load</button>
                    <button class="remove-button delete-block-item-btn" data-block-id="${block.id}" data-block-name="${escapeHtml(block.block_name)}">Delete</button>
                </div>
            `;
            savedBlocksList.appendChild(listItem);
        });

        // Attach listeners for newly rendered load and delete buttons
        savedBlocksList.querySelectorAll('.load-block-item-btn').forEach(button => {
            button.addEventListener('click', (event) => {
                const blockId = event.target.dataset.blockId;
                loadBlock(blockId);
            });
        });
        savedBlocksList.querySelectorAll('.delete-block-item-btn').forEach(button => {
            button.addEventListener('click', (event) => {
                const blockId = event.target.dataset.blockId;
                const blockName = event.target.dataset.blockName;
                confirmDeleteBlock(blockId, blockName);
            });
        });
    };

    /**
     * Loads a saved content block into the current active Quill editor.
     * @param {string} blockId The ID of the block to load.
     */
    const loadBlock = async (blockId) => {
        if (!currentQuillEditor) {
            showModalMessage('No Active Editor', 'Please click inside an article content editor to select it before loading a content block.');
            return;
        }

        try {
            const response = await apiCall('fetch_reusable_blocks.php', { blockId: blockId }); // Assuming this endpoint handles loading a single block by ID
            if (response.success && response.data) {
                const blockContent = response.data.block_content;
                currentQuillEditor.clipboard.dangerouslyPasteHTML(blockContent); // Insert HTML into Quill
                hideModal(loadBlockModal);
                showModalMessage('Block Loaded', `Content block "${response.data.block_name}" loaded successfully!`);
                generateHtmlAndPreview(); // Update preview after loading block
            } else {
                showModalMessage('Load Block Error', `Failed to load block: ${response.message || 'No data found.'}`);
            }
        } catch (error) {
            console.error('Error loading block:', error);
            showModalMessage('Load Block Error', `An unexpected error occurred while loading block: ${error.message}`);
        }
    };

    /**
     * Confirms and deletes a saved content block from the backend.
     * @param {string} blockId The ID of the block to delete.
     * @param {string} blockName The name of the block for confirmation message.
     */
    const confirmDeleteBlock = (blockId, blockName) => {
        showConfirmModal(
            'Confirm Delete Block',
            `Are you sure you want to delete the content block "${blockName}"? This action cannot be undone.`,
            'confirm'
        ).then(async confirmed => {
            if (confirmed) {
                try {
                    const response = await apiCall('delete_reusable_block.php', { blockId: blockId }); // Corrected endpoint
                    if (response.success) {
                        showModalMessage('Block Deleted!', `Content block "${blockName}" deleted successfully.`);
                        handleBlockSearch(); // Refresh the list of saved blocks in the modal
                    } else {
                        showModalMessage('Delete Block Error', `Failed to delete block: ${response.message}`);
                    }
                } catch (error) {
                    console.error('Error deleting block:', error);
                    showModalMessage('Delete Block Error', `An unexpected error occurred while deleting block: ${error.message}`);
                }
            }
        });
    };

    /**
     * Saves the content of the current active Quill editor as a reusable block.
     * @param {string} blockName The name to save the block under.
     * @param {string} blockType The type of block (e.g., 'text', 'image', 'full-article').
     */
    const saveCurrentQuillContentAsBlock = async (blockName, blockType) => {
        if (!currentQuillEditor) {
            showModalMessage('No Active Editor', 'Please select an article block to save its content.');
            return;
        }
        if (!blockName.trim()) {
            showModalMessage('Input Required', 'Please enter a name for the content block.');
            return;
        }

        const blockContent = currentQuillEditor.root.innerHTML; // Get Quill's HTML content

        try {
            const response = await apiCall('save_reusable_block.php', { // Corrected endpoint
                block_name: blockName,
                block_type: blockType,
                block_content: blockContent,
                created_by_user_id: AppConfig.logged_in_user_id, // Pass user ID
                created_by_username: AppConfig.logged_in_user_name // Pass username
            });
            if (response.success) {
                showModalMessage('Block Saved', `Content block "${blockName}" saved successfully!`);
                // Assume saveAsModal handles block saving, close it
                hideModal(saveAsModal); // This modal is not directly related to saving a block in this flow.
                                        // If a separate "Save Block As" modal is used, hide that one.
            } else {
                showModalMessage('Save Block Error', `Failed to save block: ${response.message}`);
            }
        } catch (error) {
            console.error('Error saving block:', error);
            showModalMessage('Save Block Error', `An unexpected error occurred while saving block: ${error.message}`);
        }
    };

    /**
     * Translates the content of the current active Quill editor using a backend API.
     */
    const translateQuillContent = async () => {
        if (!currentQuillEditor) {
            showModalMessage('No Active Editor', 'Please click inside an article content editor to select it before translating.');
            return;
        }

        const originalText = originalTextarea.value.trim();
        const targetLanguage = targetLanguageSelect.value; // The value is now "French", "German", etc.

        if (!originalText) {
            showModalMessage('Input Required', 'No text to translate. Please enter content in the original text area.');
            return;
        }

        showModalMessage('Translating...', 'Please wait while content is being translated.');
        try {
            const payload = {
                contents: [
                    { role: "user", parts: [{ text: `Translate the following English text to ${targetLanguage}: "${originalText}"` }] }
                ]
            };
            // The API key should be provided by the environment, so leave it empty
            const apiKey = ""; // Canvas will provide this in runtime for gemini-2.0-flash
            const apiUrl = `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${apiKey}`;

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (response.ok) {
                const result = await response.json();
                console.log("Translation API response:", result);

                if (result.candidates && result.candidates.length > 0 &&
                    result.candidates[0].content && result.candidates[0].content.parts &&
                    result.candidates[0].content.parts.length > 0) {
                    const translatedText = result.candidates[0].content.parts[0].text;
                    translatedTextarea.value = translatedText;
                    translatedTextarea.style.color = '#000';
                } else {
                    translatedTextarea.value = 'Translation failed. Unexpected API response structure.';
                    translatedTextarea.style.color = 'red';
                    console.error("Translation API did not return expected content:", result);
                }
            } else {
                const errorText = await response.text();
                translatedTextarea.value = `Translation failed: ${response.status} ${response.statusText}. Details: ${errorText}`;
                translatedTextarea.style.color = 'red';
                console.error(`Translation API error: HTTP Status ${response.status} ${response.statusText}`, errorText);
            }
        } catch (error) {
            translatedTextarea.value = 'Error during translation. Check console for details.';
            translatedTextarea.style.color = 'red';
            console.error("Translation fetch error:", error);
        } finally {
            hideModal(messageModal); // Hide "Translating..." message regardless of success/failure
        }
    };

    /**
     * Handles adding a new personalization tag via the management modal.
     */
    const handleAddPersonalizationTag = async () => {
        const newTag = newTagInput.value.trim();
        if (!newTag) {
            showModalMessage('Input Required', 'Please enter a tag to add.');
            return;
        }

        try {
            const response = await apiCall('add_personalization_tag.php', { tag: newTag }); // Assuming this endpoint handles adding tags
            if (response.success) {
                showModalMessage('Tag Added', `Personalization tag "${newTag}" added successfully!`);
                newTagInput.value = ''; // Clear input
                fetchAndDisplayPersonalizationTags(); // Refresh lists
            } else {
                showModalMessage('Error', `Failed to add tag: ${response.message}`);
            }
        } catch (error) {
            console.error('Error adding tag:', error);
            showModalMessage('Error', `An unexpected error occurred while adding tag: ${error.message}`);
        }
    };

    /**
     * Fetches and displays available personalization tags in both the management modal and the main dropdown.
     */
    const fetchAndDisplayPersonalizationTags = async () => {
        try {
            const response = await apiCall('get_personalization_tags.php', {}); // Assuming this endpoint fetches tags
            if (response.success && Array.isArray(response.data)) {
                availableTagsList.innerHTML = ''; // Clear modal list
                personalizationTagListInDropdown.innerHTML = ''; // Clear dropdown list

                if (response.data.length === 0) {
                    availableTagsList.innerHTML = '<li class="text-gray-500">No tags available.</li>';
                    personalizationTagListInDropdown.innerHTML = '<span class="personalization-option">No tags available.</span>'; // For dropdown style
                    return;
                }
                response.data.forEach(tag => {
                    // Add to management modal list
                    const listItemModal = document.createElement('li');
                    listItemModal.textContent = tag.tag_name;
                    availableTagsList.appendChild(listItemModal);

                    // Add to personalization dropdown
                    const tagSpan = document.createElement('span');
                    tagSpan.className = 'personalization-option';
                    tagSpan.textContent = tag.tag_name;
                    tagSpan.dataset.value = tag.tag_name;
                    tagSpan.addEventListener('click', (e) => {
                        e.stopPropagation(); // Prevent closing dropdown immediately
                        insertPersonalizationTag(e.target.dataset.value);
                    });
                    personalizationTagListInDropdown.appendChild(tagSpan); // Append tagSpan to the dropdown list container
                });
            } else {
                console.error('Failed to fetch personalization tags:', response.message);
                availableTagsList.innerHTML = '<li class="text-gray-500">Error loading tags.</li>';
                personalizationTagListInDropdown.innerHTML = '<span class="personalization-option">Error loading tags.</span>';
            }
        } catch (error) {
            console.error('Error fetching personalization tags:', error);
            availableTagsList.innerHTML = '<li class="text-gray-500">Error loading tags.</li>';
            personalizationTagListInDropdown.innerHTML = '<span class="personalization-option">Error loading tags.</span>';
        }
    };

    /**
     * Inserts a personalization tag into the currently focused Quill editor.
     * @param {string} tag - The personalization tag to insert.
     */
    const insertPersonalizationTag = (tag) => {
        if (currentQuillEditor) {
            const range = currentQuillEditor.getSelection(true);
            if (range) {
                currentQuillEditor.insertEmbed(range.index, 'personalizationTag', tag); // Use custom blot
                currentQuillEditor.setSelection(range.index + 1); // Move cursor past the tag
            } else {
                // If no selection, insert at the beginning or end of content
                currentQuillEditor.insertEmbed(currentQuillEditor.getLength(), 'personalizationTag', tag);
                currentQuillEditor.setSelection(currentQuillEditor.getLength() + 1);
            }
            personalizationDropdown.classList.remove('visible'); // Hide dropdown after insertion
            generateHtmlAndPreview(); // Update preview
            showModalMessage('Tag Inserted', `Personalization tag "${tag}" inserted.`);
        } else {
            showModalMessage('No Active Editor', 'Please click inside an article content editor to select it before inserting a personalization tag.');
            personalizationDropdown.classList.remove('visible'); // Still hide dropdown
        }
    };

    /**
     * Sets the preview mode (desktop/mobile) by adjusting iframe styling.
     * @param {string} mode - 'desktop' or 'mobile'.
     */
    const setPreviewMode = (mode) => {
        if (livePreviewIframe) {
            if (mode === 'mobile') {
                livePreviewIframe.style.maxWidth = '375px'; // Common mobile width
                livePreviewIframe.style.borderRadius = '25px';
                livePreviewIframe.style.border = '10px solid #333';
            } else {
                livePreviewIframe.style.maxWidth = '720px'; // Desktop width
                livePreviewIframe.style.borderRadius = '5px';
                livePreviewIframe.style.border = '1px solid #ccc';
            }
        }
    };

    /**
     * Handles inserting an image URL from the image selector modal into a target input or Quill editor.
     * This function is now used by 'Insert Selected Image' button
     */
    const handleImageInsertion = () => {
        // This function is now specifically for inserting from the library selection
        const selectedThumb = document.querySelector('.image-thumbnail.border-blue-500');
        if (!selectedThumb) {
            showModalMessage('Selection Needed', 'Please select an image from the library to insert.');
            return;
        }

        const imageUrl = selectedThumb.getAttribute('data-image-url');
        const altText = selectedThumb.getAttribute('data-image-alt');
        const width = selectedThumb.getAttribute('data-image-width');
        const height = selectedThumb.getAttribute('data-image-height');

        const targetInputId = imageModal.dataset.targetInputId;
        const targetQuillEditorId = imageModal.dataset.targetQuillEditorId;

        if (targetQuillEditorId && quillEditors[targetQuillEditorId]) {
            // Insert into Quill editor
            const targetQuillEditor = quillEditors[targetQuillEditorId];
            const range = targetQuillEditor.getSelection(true);
            targetQuillEditor.insertEmbed(range.index, 'emailImage', { src: imageUrl, alt: altText, width: width, height: height }); // Use custom EmailImageBlot
            showModalMessage('Image Inserted', 'Image inserted into editor!');
        } else if (targetInputId) {
            // Insert into a standard input field
            const targetInput = document.getElementById(targetInputId);
            if (targetInput) {
                targetInput.value = imageUrl;
                showModalMessage('Image Selected', 'Image URL inserted into input field!');
                // Manually trigger input event for the non-Quill input field
                const event = new Event('input', { bubbles: true });
                targetInput.dispatchEvent(event); // This will trigger the debounced preview update
            } else {
                showModalMessage('Error', 'Target input field not found for image URL.');
            }
        } else {
            showModalMessage('Error', 'No target found for image insertion (neither input nor Quill editor).');
        }
        hideModal(imageModal);
    };

    /**
     * Helper to copy text to clipboard using execCommand as fallback for navigator.clipboard
     * @param {string} text The text to copy.
     */
    function copyTextWithExecCommand(text) {
        const tempTextArea = document.createElement('textarea');
        tempTextArea.value = text;
        tempTextArea.style.position = 'fixed'; // Avoid scrolling to bottom
        tempTextArea.style.opacity = '0'; // Make it invisible
        tempTextArea.style.pointerEvents = 'none'; // Make it non-interactive
        document.body.appendChild(tempTextArea);
        tempTextArea.select();
        try {
            document.execCommand('copy');
            showModalMessage('Copied!', 'Text copied to clipboard.');
        } catch (err) {
            console.error('Failed to copy to clipboard (execCommand fallback):', err);
            showModalMessage('Copy Error', 'Failed to copy text. Please copy manually.');
        } finally {
            document.body.removeChild(tempTextArea);
        }
    }

    /**
     * Fetches and displays images from the image_metadata.json file in the image library modal.
     */
    async function loadImageLibrary() {
        imageLibrary.innerHTML = '<p class="text-center text-gray-500 col-span-full">Loading images...</p>';
        if (insertSelectedImageBtn) insertSelectedImageBtn.disabled = true; // Disable insert button by default

        try {
            const response = await fetch(`${AppConfig.base_app_url}api/uploads/image_metadata.json`); // Corrected path
            if (!response.ok) {
                if (response.status === 404) {
                    imageLibrary.innerHTML = '<p class="text-center text-gray-500 col-span-full">No images in library yet. Upload some!</p>';
                    return;
                }
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const imagesMetadata = await response.json();

            imageLibrary.innerHTML = ''; // Clear loading message

            if (imagesMetadata.length > 0) {
                imagesMetadata.forEach(image => {
                    const imgThumb = document.createElement('div');
                    imgThumb.classList.add('image-thumbnail', 'relative', 'border', 'rounded-lg', 'overflow-hidden', 'cursor-pointer', 'group', 'hover:border-blue-500', 'hover:ring-2', 'hover:ring-blue-500');
                    imgThumb.setAttribute('data-image-url', image.publicUrl);
                    imgThumb.setAttribute('data-image-alt', image.description || '');
                    imgThumb.setAttribute('data-image-width', image.resizedWidth || '');
                    imgThumb.setAttribute('data-image-height', image.resizedHeight || '');
                    imgThumb.setAttribute('data-image-type', image.type || 'custom'); // Store image type

                    imgThumb.innerHTML = `
                        <img src="${image.publicUrl}" alt="${image.description || 'Uploaded Image'}" class="w-full h-24 object-cover">
                        <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity p-2">
                            <span class="text-white text-xs text-center break-words">${image.description || image.filename}</span>
                        </div>
                    `;
                    imageLibrary.appendChild(imgThumb);

                    imgThumb.addEventListener('click', () => {
                        // Remove selection from others
                        document.querySelectorAll('.image-thumbnail').forEach(thumb => {
                            thumb.classList.remove('border-blue-500', 'ring-2', 'ring-blue-500', 'selected-image');
                        });
                        // Add selection to current
                        imgThumb.classList.add('border-blue-500', 'ring-2', 'ring-blue-500', 'selected-image');
                        if (insertSelectedImageBtn) insertSelectedImageBtn.disabled = false;
                    });
                });
            } else {
                imageLibrary.innerHTML = '<p class="text-center text-gray-500 col-span-full">No images in library yet. Upload some!</p>';
            }
        } catch (error) {
            console.error('Error loading image library:', error);
            imageLibrary.innerHTML = '<p class="text-center text-red-500 col-span-full">Failed to load image library. Please try again.</p>';
            showModalMessage('Image Library Error', `Failed to load image library: ${error.message}`);
        }
    }

    /**
     * Handles the submission of the image upload form.
     * @param {Event} event - The form submission event.
     */
    async function handleImageUpload(event) {
        event.preventDefault(); // Prevent default form submission

        const imageUploadForm = document.getElementById('imageUploadForm');
        const formData = new FormData(imageUploadForm); // Get form data, including the file

        // Basic validation
        if (!formData.get('imageFile').name) {
            await showModalMessage('Upload Error', 'Please select an image file to upload.');
            return;
        }

        if (uploadImageBtn) {
            uploadImageBtn.textContent = 'Uploading...';
            uploadImageBtn.disabled = true;
        }


        try {
            const response = await apiCall('ajax_image_upload.php', formData, true); // true for FormData
            if (response.success) {
                await showModalMessage('Upload Success', response.message);
                // Insert image into Quill editor if an editor was active, otherwise don't
                const targetQuillEditorId = imageModal.dataset.targetQuillEditorId;
                const targetInputId = imageModal.dataset.targetInputId;

                if (targetQuillEditorId && quillEditors[targetQuillEditorId]) {
                    const targetQuillEditor = quillEditors[targetQuillEditorId];
                    const range = targetQuillEditor.getSelection(true);
                    targetQuillEditor.insertEmbed(range.index, 'emailImage', {
                        src: response.imageUrl,
                        alt: response.imageMetadata.description,
                        width: response.imageMetadata.resizedWidth,
                        height: response.imageMetadata.resizedHeight
                    });
                } else if (targetInputId) {
                    const targetInput = document.getElementById(targetInputId);
                    if (targetInput) {
                        targetInput.value = response.imageUrl;
                        // Trigger input event to ensure preview updates
                        const event = new Event('input', { bubbles: true });
                        targetInput.dispatchEvent(event);
                    }
                }

                // Reload image library to show new image
                loadImageLibrary();
                // Reset form
                imageUploadForm.reset();
                if (customImageSizeGroup) customImageSizeGroup.style.display = 'none'; // Hide custom fields
                document.querySelector('input[name="imageType"][value="main_article"]').checked = true; // Reset radio
                hideModal(imageModal); // Close the image modal
            } else {
                await showConfirmModal('Upload Failed', response.message, 'alert');
            }
        } catch (error) {
            console.error('Image upload failed:', error);
            await showConfirmModal('Upload Error', `An error occurred during image upload: ${error.message}`, 'alert');
        } finally {
            if (uploadImageBtn) {
                uploadImageBtn.textContent = 'Upload & Insert Image';
                uploadImageBtn.disabled = false;
            }
        }
    }


    // --- EVENT LISTENERS (Centralized for better management) --- //

    const setupEventListeners = () => {
        // Step 1: Layout Form (Create/Load Email) Submission
        if (layoutForm) {
            layoutForm.addEventListener('submit', (e) => {
                e.preventDefault();
                handleLayoutFormSubmit();
            });
        }

        // Step 1: Personalization Type Radio Buttons
        personalizationTypeRadios.forEach(radio => {
            radio.addEventListener('change', (e) => {
                currentPersonalizationType = e.target.value;
                updatePersonalizationUI(); // This function will rebuild the personalization dropdown
                generateHtmlAndPreview(); // Update preview when type changes
            });
        });

        // Step 1: "Search Existing Mails" Button
        if (openSearchModalBtn) {
            openSearchModalBtn.addEventListener('click', () => {
                showModal(searchEmailModal);
                // Clear search inputs when opening
                if (searchSubjectLineInput) searchSubjectLineInput.value = '';
                if (searchReferenceCodeInput) searchReferenceCodeInput.value = '';
                if (searchAuthorInput) searchAuthorInput.value = '';
                if (searchSenderEmailInput) searchSenderEmailInput.value = '';
                if (searchRegionInput) searchRegionInput.value = '';
                // Automatically perform an initial search for all emails when modal opens
                performEmailSearch();
            });
        }
        // Search Modal specific buttons and close
        if (searchEmailModal) { // Check if modal exists before attaching listeners
            searchEmailModal.querySelector('.close-button').addEventListener('click', () => hideModal(searchEmailModal));
        }
        if (performSearchBtn) {
            performSearchBtn.addEventListener('click', performEmailSearch);
        }

        // Step 1: "Duplicate Email" Button
        if (duplicateEmailBtn) {
            duplicateEmailBtn.addEventListener('click', () => {
                // This would involve fetching current email data, generating a new reference code,
                // and then saving the fetched data under the new code.
                showModalMessage('Feature Not Ready', 'Email duplication will be implemented here. This would create a new email with current content and a new reference code.');
            });
        }


        // Step 2: Article Block Actions (Add/Move/Remove)
        if (addSingleColumnButton) {
            addSingleColumnButton.addEventListener('click', () => addArticleBlock('single'));
        }
        if (addDoubleColumnButton) {
             addDoubleColumnButton.addEventListener('click', () => addArticleBlock('double'));
        }
        if (addSignOffButton) {
            addSignOffButton.addEventListener('click', () => addArticleBlock('sign_off'));
        }
        if (loadSavedBlockBtn) {
            loadSavedBlockBtn.addEventListener('click', () => {
                showModal(loadBlockModal);
                handleBlockSearch(); // Load blocks when modal opens
            });
        }
        if (globalTranslateButton) {
            globalTranslateButton.addEventListener('click', () => {
                if (currentQuillEditor) {
                    originalTextarea.value = currentQuillEditor.getText(); // Pre-fill with current editor's text
                    translatedTextarea.value = ''; // Clear previous translation
                    showModal(translateModal);
                } else {
                    showModalMessage('No Active Editor', 'Please click inside an article content editor to select it, then try translating.');
                }
            });
        }
        // Global move/remove buttons (operate on selectedArticleIndex)
        if (moveUpBtn) {
            moveUpBtn.addEventListener('click', () => moveArticleBlock(selectedArticleIndex, -1));
        }
        if (moveDownBtn) {
            moveDownBtn.addEventListener('click', () => moveArticleBlock(selectedArticleIndex, 1));
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', () => removeArticleBlock()); // No index needed, uses selectedArticleIndex
        }

        // Step 2: General Settings Inputs (debounced updates)
        [subjectLineInput, preheaderTextInput, backgroundImageInput, emailFromInput, senderNameInput, senderEmailInput, audienceSelect, sendTimeInput, logoUrlInput, senderMobileInput].forEach(input => {
            if (input) input.addEventListener('input', debounce(generateHtmlAndPreview, 300));
        });
        if (regionSelect) {
            regionSelect.addEventListener('change', debounce(generateHtmlAndPreview, 300));
        }
        // Listener for the general settings "Select Image" button
        const mainBackgroundImageSelectBtn = document.querySelector('button[data-target-input-id="backgroundImage"]');
        if (mainBackgroundImageSelectBtn) {
            mainBackgroundImageSelectBtn.addEventListener('click', (event) => {
                imageModal.dataset.targetInputId = 'backgroundImage'; // Set target input ID
                imageModal.dataset.targetQuillEditorId = ''; // Clear Quill target
                if (imageFile) imageFile.value = ''; // Clear file input
                if (imageDescription) imageDescription.value = ''; // Clear description
                document.querySelector('input[name="imageType"][value="main_article"]').checked = true; // Reset radio
                customImageSizeGroup.style.display = 'none'; // Hide custom fields
                showModal(imageModal); // Open the image selector modal
            });
        }


        // Step 3: Code and Actions Buttons
        if (generateAndSaveEmailBtn) {
            generateAndSaveEmailBtn.addEventListener('click', async () => {
                showModalMessage('Saving Email', 'Generating and saving your email...');
                try {
                    const formData = getCurrentFormData();
                    // If no reference code exists (new email), generate one
                    if (!formData.referenceCode) { // Changed from reference_code
                        formData.referenceCode = generateReferenceCode();
                    }
                    const response = await apiCall('save_email.php', formData);
                    if (response.success) {
                        showModalMessage('Success', 'Email generated and saved successfully!');
                        // Update currentReferenceCode and the input field for a new email if it was a new save
                        currentReferenceCode = formData.referenceCode; // Changed from reference_code
                        referenceCodeInput.value = currentReferenceCode;
                        displayReferenceCodeSpan.textContent = currentReferenceCode;
                        currentReferenceCodeDisplayDiv.style.display = 'block';
                    } else {
                        showModalMessage('Error', `Failed to save email: ${response.message}`);
                    }
                } catch (error) {
                    console.error('Error saving email:', error);
                    // apiCall already shows a message, but this catch ensures any remaining errors are caught
                } finally {
                    hideModal(messageModal); // Hide saving message
                }
            });
        }

        if (copyHtmlBtn) {
            copyHtmlBtn.addEventListener('click', () => {
                copyTextWithExecCommand(htmlOutputTextarea.value); // Use our new helper function
            });
        }

        if (downloadHtmlBtn) {
            downloadHtmlBtn.addEventListener('click', () => {
                const filename = `${currentReferenceCode || 'new-email'}.html`;
                const element = document.createElement('a');
                element.setAttribute('href', 'data:text/html;charset=utf-8,' + encodeURIComponent(htmlOutputTextarea.value));
                element.setAttribute('download', filename);
                element.style.display = 'none';
                document.body.appendChild(element);
                element.click();
                document.body.removeChild(element);
                showModalMessage('Downloaded!', 'Email HTML downloaded.');
            });
        }

        if (saveAsEmailBtn) {
            saveAsEmailBtn.addEventListener('click', () => {
                if (newReferenceCodeInput) newReferenceCodeInput.value = ''; // Clear for new input
                if (newEmailTitleInput) newEmailTitleInput.value = subjectLineInput.value || ''; // Pre-fill with current subject
                showModal(saveAsModal);
            });
        }

        // Personalization dropdown and management
        if (personalizationButton) {
            personalizationButton.addEventListener('click', (e) => {
                e.stopPropagation(); // Prevent closing immediately
                personalizationDropdown.classList.toggle('visible');
                if (personalizationDropdown.classList.contains('visible')) {
                    fetchAndDisplayPersonalizationTags(); // Refresh dropdown content when opened
                }
            });
        }
        if (manageTagsBtn) {
            manageTagsBtn.addEventListener('click', () => {
                hideModal(personalizationDropdown); // Hide the dropdown if open
                showModal(personalizationTagModal);
                fetchAndDisplayPersonalizationTags(); // Populate management modal
            });
        }

        if (sendTestEmailButton) {
            sendTestEmailButton.addEventListener('click', async () => {
                const recipient = testEmailRecipientInput.value.trim();
                if (!recipient) {
                    showModalMessage('Missing Recipient', 'Please enter a test email recipient.');
                    return;
                }
                const emailContent = htmlOutputTextarea.value;
                if (!emailContent) {
                    showModalMessage('No Email Content', 'Generate email content first.');
                    return;
                }

                showModalMessage('Sending Test Email', 'Please wait...');
                try {
                    const response = await apiCall('send_test_email.php', { recipient: recipient, html_content: emailContent });
                    if (response.success) {
                        showModalMessage('Success', 'Test email sent successfully!');
                    } else {
                        showModalMessage('Error', `Failed to send test email: ${response.message}`);
                    }
                } catch (error) {
                    console.error('Error sending test email:', error);
                    // apiCall already shows a message
                } finally {
                    hideModal(messageModal); // Hide sending message
                }
            });
        }


        // Modal specific event listeners for actions within modals
        if (confirmSaveAsBtn) {
            confirmSaveAsBtn.addEventListener('click', handleSaveAsEmail);
        }

        if (performBlockSearchBtn) {
            performBlockSearchBtn.addEventListener('click', handleBlockSearch);
        }

        if (initiateTranslationBtn) {
            initiateTranslationBtn.addEventListener('click', translateQuillContent);
        }
        // Listener for the "Copy Translated Text" button inside the Translate Modal
        if (insertTranslatedTextBtn) {
            insertTranslatedTextBtn.addEventListener('click', () => {
                if (translatedTextarea && translatedTextarea.value) {
                    copyTextWithExecCommand(translatedTextarea.value);
                    hideModal(translateModal); // Close modal after copying
                } else {
                    showModalMessage('Copy Error', 'No translated text available to copy.');
                }
            });
        }

        if (addTagBtn) {
            addTagBtn.addEventListener('click', handleAddPersonalizationTag);
        }
        // No longer exists directly: if (imageSelectorModal) { imageSelectorModal.querySelector('#insertImageBtn').addEventListener('click', handleImageInsertion); }

        // Listener for the OK button in the Message Modal
        if (messageModalOkBtn) {
            messageModalOkBtn.addEventListener('click', () => hideModal(messageModal));
        }


        // Global modal close listeners (event delegation for `close-button` and backdrop)
        document.body.addEventListener('click', (event) => {
            if (event.target.classList.contains('close-button')) {
                // Find the closest parent with class 'app-modal' or specific modal ID to hide it
                const modalToClose = event.target.closest('.app-modal');
                if (modalToClose) {
                    hideModal(modalToClose);
                }
            } else if (event.target === modalBackdrop) {
                // Clicking on backdrop closes all currently open modals
                document.querySelectorAll('.app-modal[style*="display: block"]').forEach(modal => {
                    modal.style.display = 'none';
                });
                modalBackdrop.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

        // Close personalization dropdown when clicking elsewhere (not on button or dropdown itself)
        document.addEventListener('click', (e) => {
            if (personalizationDropdown && !personalizationDropdown.contains(e.target) && !personalizationButton.contains(e.target)) {
                personalizationDropdown.classList.remove('visible');
            }
        });

        // Preview controls (Desktop/Mobile buttons)
        if (previewDesktopBtn) {
            previewDesktopBtn.addEventListener('click', () => setPreviewMode('desktop'));
        }
        if (previewMobileBtn) {
            previewMobileBtn.addEventListener('click', () => setPreviewMode('mobile'));
        }

        // Image Modal specific event listeners
        if (imageTypeRadios) {
            imageTypeRadios.forEach(radio => {
                radio.addEventListener('change', () => {
                    if (radio.value === 'custom') {
                        if (customImageSizeGroup) customImageSizeGroup.style.display = 'block';
                    } else {
                        if (customImageSizeGroup) customImageSizeGroup.style.display = 'none';
                    }
                });
            });
            // Ensure initial state of custom size group is correct on page load
            const initialSelectedImageType = document.querySelector('input[name="imageType"]:checked');
            if (initialSelectedImageType && initialSelectedImageType.value !== 'custom') {
                if (customImageSizeGroup) customImageSizeGroup.style.display = 'none';
            } else if (!initialSelectedImageType) { // Default to main_article if nothing is checked
                document.querySelector('input[name="imageType"][value="main_article"]').checked = true;
                if (customImageSizeGroup) customImageSizeGroup.style.display = 'none';
            }
        }

        if (uploadImageBtn) {
            uploadImageBtn.addEventListener('click', handleImageUpload);
        }
        if (insertSelectedImageBtn) {
            insertSelectedImageBtn.addEventListener('click', handleImageInsertion); // This now inserts from library
        }
        if (imageLibrarySearch) {
            imageLibrarySearch.addEventListener('input', (event) => {
                const searchTerm = event.target.value.toLowerCase();
                document.querySelectorAll('#imageLibrary .image-thumbnail').forEach(thumb => {
                    const altText = thumb.getAttribute('data-image-alt').toLowerCase();
                    const imageUrl = thumb.getAttribute('data-image-url').toLowerCase();
                    const filename = thumb.getAttribute('data-filename') ? thumb.getAttribute('data-filename').toLowerCase() : '';
                    if (altText.includes(searchTerm) || imageUrl.includes(searchTerm) || filename.includes(searchTerm)) {
                        thumb.style.display = 'block';
                    } else {
                        thumb.style.display = 'none';
                    }
                });
            });
        }
    };

    /**
     * Initializes the entire application. This function is called once the DOM is fully loaded.
     */
    const initializeApp = async () => {
        setupEventListeners();
        // Trigger initial form submission to load an email or create a new one.
        // This simulates clicking "Start New Email" or loading by default.
        await handleLayoutFormSubmit();
    };

    // Call the initializer function to start the application
    initializeApp();
});
