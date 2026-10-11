<?php
function homeAssistantConfig(): void
{
    if ($_SERVER['REQUEST_URI'] != '/' && $_SERVER['REQUEST_URI'] != '/index.php') {
        return;
    }
    if (!key_exists('HTTP_X_INGRESS_PATH', $_SERVER)) {
        return;
    }
    if (!str_starts_with($_SERVER['HTTP_X_INGRESS_PATH'], '/api/ingress/') && !str_starts_with($_SERVER['HTTP_X_INGRESS_PATH'], '/api/hassio_ingress/')) {
        return;
    }
    
    // Determine the hostname with fallback
    $myHostname = '4edeb129-skodalnxcmd-service';
    if (gethostname()) {
        $myHostname = gethostname();
    }
    
    // Read file
    $templatePath = '/var/www/skoda.yaml.template';
    if (!file_exists($templatePath)) {
        $templatePath = '/var/www/sample.skoda.yaml';
    }
    $yamlContent = '';
    
    if (file_exists($templatePath)) {
        $rawTemplate = file_get_contents($templatePath);
        // Replace host name place holder
        $yamlContent = str_replace('{{ADDON_HOSTNAME}}', $myHostname, $rawTemplate);
    } else {
        $yamlContent = "# Error: Template file missing inside container.";
    }
    
    // Config webpage
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Škoda HA Setup</title>
        <style>
            body {
                background: #1c1c1e;
                color: #f2f2f7;
                font-family: -apple-system, sans-serif;
                padding: 20px;
            }

            pre {
                background: #2c2c2e;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #3a3a3c;
                overflow-x: auto;
                font-family: monospace;
            }

            button {
                background: #0a84ff;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 6px;
                font-weight: bold;
                cursor: pointer;
            }

            button:hover {
                background: #0070e3;
            }
        </style>
    </head>
    <body>
    <h3>Škoda Configuration Package</h3>
    <p>Add the following two lines into /config/configuration.yaml</p>
    <pre>homeassistant:
packages: !include_dir_named packages</pre>
    <p>Copy this auto-configured block into your <code>/config/packages/skoda.yaml</code> file:</p>

    <pre id="configBlock"><?php echo htmlspecialchars($yamlContent); ?></pre>

    <button onclick="copyConfig()">Copy Configuration</button>

    <script>
        function copyConfig() {
            const text = document.getElementById('configBlock').innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('Configuration copied to clipboard!');
            });
        }
    </script>
    </body>
    </html>
    <?php
    exit;
}

if (key_exists('HTTP_X_INGRESS_PATH', $_SERVER)) {
   homeAssistantConfig(); 
}


// Else Normal mode (Non HA)
switch ($_SERVER['REQUEST_URI']) {
    
    case '/status':
        passthru('php /usr/local/bin/skodaLnxCmd.php json');
        break;
        
    case '/support':
        passthru('php /usr/local/bin/skodaLnxCmd.php support');
        break;
    
    case '/charge/on':
        passthru('php /usr/local/bin/skodaLnxCmd.php charge-on');
        break;
    
    case '/charge/off':
        passthru('php /usr/local/bin/skodaLnxCmd.php charge-off');
        break;
        
    case '/ac/on':
        passthru('php /usr/local/bin/skodaLnxCmd.php ac');
        break;
    
    case '/ac/off':
    case '/hvac/reset':
    case '/reset':
        passthru('php /usr/local/bin/skodaLnxCmd.php reset');
        break;
    
    default:
        http_response_code(404);
}
