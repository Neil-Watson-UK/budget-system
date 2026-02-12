<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">
    <h1>Form Submission Debug</h1>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Test Form 1 (Simple)</div>
                <div class="card-body">
                    <form method="POST" action="delete.php">
                        <input type="hidden" name="id" value="144">
                        <input type="hidden" name="csrf_token" value="test123">
                        <button type="submit" class="btn btn-primary">Test POST Submit</button>
                    </form>
                    <p class="mt-2 text-muted">Simple form, no JavaScript</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Test Form 2 (With onclick)</div>
                <div class="card-body">
                    <form method="POST" action="delete.php">
                        <input type="hidden" name="id" value="144">
                        <input type="hidden" name="csrf_token" value="test123">
                        <button type="submit" 
                                class="btn btn-danger" 
                                onclick="return confirm('Really delete?');">
                            Test with onclick
                        </button>
                    </form>
                    <p class="mt-2 text-muted">Form with onclick confirmation</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Test Form 3 (Your current setup)</div>
                <div class="card-body">
                    <form method="POST" 
                          action="delete.php" 
                          onsubmit="return confirm('Really delete?');">
                        <input type="hidden" name="id" value="144">
                        <input type="hidden" name="csrf_token" value="test123">
                        <button type="submit" class="btn btn-warning">
                            Test with onsubmit
                        </button>
                    </form>
                    <p class="mt-2 text-muted">Form with onsubmit (your current setup)</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Test Form 4 (JavaScript submit)</div>
                <div class="card-body">
                    <form id="jsForm" method="POST" action="delete.php">
                        <input type="hidden" name="id" value="144">
                        <input type="hidden" name="csrf_token" value="test123">
                        <button type="button" 
                                class="btn btn-info" 
                                onclick="if(confirm('Delete?')) { document.getElementById('jsForm').submit(); }">
                            JavaScript submit
                        </button>
                    </form>
                    <p class="mt-2 text-muted">Manual JavaScript submission</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mt-4">
        <h3>Current Browser Info:</h3>
        <pre id="browserInfo"></pre>
    </div>
    
    <script>
        // Display browser info
        document.getElementById('browserInfo').textContent = 
            'User Agent: ' + navigator.userAgent + '\n' +
            'Platform: ' + navigator.platform + '\n' +
            'Cookies Enabled: ' + navigator.cookieEnabled;
            
        // Log form submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                console.log('Form submitting:', this.method, this.action);
            });
        });
    </script>
</body>
</html>