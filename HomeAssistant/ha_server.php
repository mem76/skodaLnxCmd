<?php
switch ($_SERVER['REQUEST_URI']) {
    
    case '/status':
        passthru('php /usr/local/bin/skodaLnxCmd.php json');
        break;
        
    case '/support':
        passthru('php /usr/local/bin/skodaLnxCmd.php support');
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
