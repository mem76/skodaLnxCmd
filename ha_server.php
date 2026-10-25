<?php
// Simple web server.
// How to start from a Dockerfile.
// CMD ["php", "-S", "0.0.0.0:8080", "/var/www/ha_server.php"]
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

    case '/heat/on':
        passthru('php /usr/local/bin/skodaLnxCmd.php heat');
        break;
    
    case '/heat/off':
        passthru('php /usr/local/bin/skodaLnxCmd.php heat-off');
        break;
        
    case '/vent/on':
        passthru('php /usr/local/bin/skodaLnxCmd.php vent');
        break;
    
    case '/ac/off':
    case '/hvac/reset':
    case '/reset':
        passthru('php /usr/local/bin/skodaLnxCmd.php reset');
        break;
    
    default:
        http_response_code(404);
}
