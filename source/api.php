<?php

// benign change

header('Content-Type: application/json');

$port = 7142;

$request_method = $_SERVER['REQUEST_METHOD'];

$function = isset($_GET['function']) ? $_GET['function'] : 'device';

$post_data = json_decode(file_get_contents('php://input'),true);

$request_method = $_SERVER['REQUEST_METHOD'];

$host = $_GET['host'];

// where we'll store the output data sent back to caller
$output = array();

// connect to TV
$socket = open_socket($host, $port);

// if an array was returned instead of a socket, then something went wrong
if(is_array($socket)) {
    http_response_code(500);
    $output = $socket;
    print json_encode($output, JSON_PRETTY_PRINT);
    exit;
}

if($function == "device") {
    // get device info
    if ($request_method == "GET") {
        $power_response = get_power($socket);

        // print_r($power_response);

        // if we got a response to our power status check
        if(is_array($power_response) && !empty($power_response)) {
            $output = array_merge($output, $power_response);

            if(array_key_exists("power_status", $power_response) && $power_response['power_status']) {

                // get video input status
                $input_response = get_input($socket);
    
                if(is_array($input_response) && !empty($input_response)) {
                    $output = array_merge($output, $input_response);
                }
    
                // get volume status
                $volume_response = get_volume($socket);
    
                if(is_array($volume_response) && !empty($volume_response)) {
                    $output = array_merge($output, $volume_response);
                }

                // get mute status
                $mute_response = get_mute_status($socket);

                if(is_array($mute_response) && !empty($mute_response)) {
                    $output = array_merge($output, $mute_response);
                }
            }
        }
    }elseif($request_method == "PUT") {
        if(array_key_exists("power_state", $post_data) && ($post_data['power_state'] == "on" || $post_data['power_state'] == "off")) {
            $desired_power_state = $post_data['power_state'];

            $set_power_response = set_power($socket, $desired_power_state);

            if(is_array($set_power_response) && !empty($set_power_response)) {
                $output = array_merge($output, $set_power_response);
            }

        }

        if(array_key_exists("video_input_num", $post_data)) {
            $desired_input = $post_data['video_input_num'];

            $set_input_response = set_input($socket, $desired_input);

            if(is_array($set_input_response) && !empty($set_input_response)) {
                $output = array_merge($output, $set_input_response);
            }
        }
    }elseif($request_method == "POST") {
        if(array_key_exists("audio_volume", $post_data)) {
            if(array_key_exists("audio_volume", $post_data)) {
                $desired_volume = $post_data['audio_volume'];
    
                $set_volume_response = set_volume($socket, $desired_volume);
    
                if(is_array($set_volume_response) && !empty($set_volume_response)) {
                    $output = array_merge($output, $set_volume_response);
                }
            }
        }

        if(array_key_exists("audio_mute", $post_data)) {
            $mute_status = $post_data['audio_mute'];

            $set_mute_response = set_mute($socket, $mute_status);

            if(is_array($set_mute_response) && !empty($set_mute_response)) {
                $output = array_merge($output, $set_mute_response);
            }
        }
    }
}

socket_close($socket);

print json_encode($output, JSON_PRETTY_PRINT);


/////////////////////////////////////////
//
// Functions
//
/////////////////////////////////////////


function open_socket($host, $port) {

    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    // set socket receive and send timeouts to 5 seconds
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec'=>5, 'usec'=>0));
    socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec'=>5, 'usec'=>0));

    if($socket) {

        $connect_result = @socket_connect($socket, $host, $port);

        if($connect_result) {
            // do nothing, we'll just return the socket
        } else {
            $errorcode = socket_last_error();
            $errormsg = socket_strerror($errorcode);
            $socket = array("error_num" => $errorcode, "error_message" => $errormsg);
        }
    }else {
        $errorcode = socket_last_error();
        $errormsg = socket_strerror($errorcode);
        $socket = array("error_num" => $errorcode, "error_message" => $errormsg);
    }

    return $socket;
}

function get_power($socket) {
    global $host ;

    $command = hex2bin('01304130433036023030443603770d');

    // send command to socket
    socket_write($socket, $command, strlen($command)) or die("2826272288 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024);

    $power_state_reply_from_nec = bin2hex($response);

    if( file_exists("/tmp/last_set_power_".md5($host)) &&
        (time()-filemtime("/tmp/last_set_power_".md5($host)))<5 ) {
        $power_state = json_decode( file_get_contents("/tmp/last_set_power_".md5($host)), true ) ;
    }elseif($power_state_reply_from_nec == "01303041443132023030303044363031303030343030303103710d" ||
       $power_state_reply_from_nec == "01303041443132023030303044363030303030343030303103700d" ||
       $power_state_reply_from_nec == "") {
        $power_state = array("power_status" => 1, "power_status_description" => "on");

    }elseif($power_state_reply_from_nec == "01303041443132023030303044363031303030343030303403740d" ||
            $power_state_reply_from_nec == "01303041443132023030303044363030303030343030303403750d" ) {
        $power_state = array("power_status" => 0, "power_status_description" => "off");
    }elseif($power_state_reply_from_nec == "0130304142303402424503010d") {
        $power_state = json_decode( file_get_contents("/tmp/last_set_power_".md5($host)), true ) ;
    }else{
        $power_state = array("power_status" => NULL, "error_num" => 8282827, "error_message" => "unknown power response: {$power_state_reply_from_nec}");
    }

    return $power_state;
}

function get_input($socket) {
    // string to get the current video input from NEC
    $command = hex2bin('01304130433036023030363003030D');

    // send command to socket
    socket_write($socket, $command, strlen($command)) or die("2871794938 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024);

    $raw_reply_from_nec = bin2hex($response);

    if(!empty($raw_reply_from_nec)) {
        $raw_reply_from_nec = strtolower($raw_reply_from_nec);
        switch ($raw_reply_from_nec) {
            // todo look to see how physical ports are numbered
            case '01303041443132023030303036303030303038303030313103090d':
                $input_return = array("video_input_num" => 1, "video_input_type" => "hdmi");
                break;
            case '013030414431320230303030363030303030383230303131030b0d':
                $input_return = array("video_input_num" => 1, "video_input_type" => "hdmi");
                break;
            case '013030414431320230303030363030303030383030303132030a0d':
                $input_return = array("video_input_num" => 2, "video_input_type" => "hdmi");
                break;
            case '01303041443132023030303036303030303038323030313203080d':
                $input_return = array("video_input_num" => 2, "video_input_type" => "hdmi");
                break;
            default:
                $input_return = array("active_input_num" => NULL, "error_num" => 3837283, "error_message" => "unknown input");
                break;
        }
    }

    return $input_return;
}

function get_volume($socket) {

    // string to get the current volume from NEC
    $command = hex2bin('01304130433036023030363203010d');

    // send command to socket
    socket_write($socket, $command, strlen($command)) or die("4636252739 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024) or die("5536268927 - Could not read server response\n");

    $volume_raw_reply_from_nec = bin2hex($response);

    // convert the raw response we get from the NEC to an integer
    $volume = volume_reply_to_int($volume_raw_reply_from_nec);

    $output = array("audio_volume" => $volume);

    return $output;
}

function set_power($socket, $state) {
    global $host ;

    if($state == "on" || $state === true || $state === 1) {
        $command = hex2bin('01304130413043024332303344363030303103730d');
        file_put_contents( "/tmp/last_set_power_".md5($host), json_encode(array("power_status" => 1, "power_status_description" => "on")) ) ;
    }elseif($state == "off" || $state === false || $state === 0) {
        $command = hex2bin('01304130413043024332303344363030303403760d');
        file_put_contents( "/tmp/last_set_power_".md5($host), json_encode(array("power_status" => 0, "power_status_description" => "off")) ) ;
    }else {
        $output = array("power_status" => NULL, "error_num" => 60, "error_message" => "unknown power command");
        return $output;
    }
    
    // send command to socket
    socket_write($socket, $command, strlen($command)) or die("Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024) or die("238272829 - Could not read server response\n");

    $raw_reply_from_nec = bin2hex($response);

    // print "\nraw_reply to set_power: " . $raw_reply_from_nec . "\n";
    // response to power on:  013030414230450230304332303344363030303103760d
    // response to power off: 013030414230450230304332303344363030303403730d

    if($raw_reply_from_nec == "013030414230450230304332303344363030303103760d") {
        $output = array("power_status" => "on");
    }
    elseif($raw_reply_from_nec == "013030414230450230304332303344363030303403730d") {
        $output = array("power_status" => "off");
    } else {
        $output = array("power_status" => NULL, "error_num" => 200, "error_message" => "failed to set power");
    }

    return $output;
}

function set_input($socket, $input_number) {

    switch ($input_number) {
        case '1':
            $command = hex2bin("0130413045304102303036303030313103720d");
            // print "set to input 1.\n";
            break;
        case '2':
            $command = hex2bin("0130413045304102303036303030313203710d");
            // print "set to input 2.\n";
            break;
        default:
            $command = NULL;
            $input_return = array("video_input_num" => NULL, "error_num" => "937274", "error_message" => "trying to set unknown input");
            break;
    }
    
    // send command to socket
    socket_write($socket, $command, strlen($command)) or die("347 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024) or die("348 - Could not read server response\n");

    $raw_reply_from_nec = bin2hex($response);

    // print "raw reply from nec: " . $raw_reply_from_nec . "\n";

    if($raw_reply_from_nec == "013030414631320230303030363030303030383030303131030b0d"){
        $output = array("video_input_num" => 1);
    }
    elseif($raw_reply_from_nec == "01303041463132023030303036303030303038323030313103090d"){
        $output = array("video_input_num" => 1);
    }
    elseif($raw_reply_from_nec == "01303041463132023030303036303030303038303030313203080d"){
        $output = array("video_input_num" => 2);
    }
    elseif($raw_reply_from_nec == "013030414631320230303030363030303030383230303132030a0d"){
        $output = array("video_input_num" => 2);
    } 
    else {
        $output = array("video_input_num" => NULL, "error_num" => 200, "error_message" => "failed to set input");
    }

    return $output;

}

function set_volume($socket, $volume_adjustment) {

    // if "up" or "down" is passed, convert it to +2 or -2
    if($volume_adjustment == "up") {
        $volume_adjustment = "+2";
    }elseif($volume_adjustment == "down") {
        $volume_adjustment = "-2";
    }

    // volume_adjustment can be passed as an absolute value 0 - 100
    // or add or subtract from the current volume by prepending the increment/decrement with a + or - symbol (eg. +5 or -10)
    preg_match('/(\+|-)?([0-9]+)/', $volume_adjustment, $matches);

    // if a + or - was NOT passed, then we'll just set the volume to the passed value
    if(empty($matches[1])) {

        $desired_volume = $matches[2];

        $volume_set_command = volume_set_value_mapper($desired_volume);

        // print "volume_set_command: " . bin2hex($volume_set_command) . "\n";

    }elseif(!empty($matches[1] && is_numeric($matches[2]))) {
        // we are going to increment or decrement the volume
        // first get the current volume
        $current_volume = get_volume($socket);

        if($matches[1] == "+") {
            $desired_volume = $current_volume['audio_volume'] + $matches[2];

            if($desired_volume > 100) { $desired_volume = 100;}

            $volume_set_command = volume_set_value_mapper($desired_volume);

            // print "+desired volume: " . $desired_volume . "\n";

        }elseif($matches[1] == "-") {
            $desired_volume = $current_volume['audio_volume'] - $matches[2];

            if($desired_volume < 0) { $desired_volume = 0;}

            $volume_set_command = volume_set_value_mapper($desired_volume);

            // print "-desired volume: " . $desired_volume . "\n";
        }
    }

    // send command to socket
    socket_write($socket, $volume_set_command, strlen($volume_set_command)) or die("832 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024) or die("833 - Could not read server response\n");

    $raw_reply_from_nec = bin2hex($response);

    $new_volume_int = set_volume_response_mapper($raw_reply_from_nec);

    // print "raw reply from nec: " . $raw_reply_from_nec . "\n";

    $volume_return = array("audio_volume" => $new_volume_int);

    return $volume_return;
}

function get_mute_status($socket) {
    // string to get the current mute status
    $command = hex2bin('01304130433036023030384403790d');

    // send command to socket
    socket_write($socket, $command, strlen($command)) or die("372694750 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024) or die("372694751 - Could not read server response\n");

    $raw_reply_from_nec = bin2hex($response);

    // match the raw response we get from the NEC
    if($raw_reply_from_nec == "013030414431320230303030384430303030303230303032037b0d" || $raw_reply_from_nec == "013030414631320230303030384430303030303230303030037b0d") {
        $audio_muted = false;
    }elseif($raw_reply_from_nec == "01303041443132023030303038443030303030323030303103780d") {
        $audio_muted = true;
    }else{
        $audio_muted = NULL;
    }

    $output = array("audio_muted" => $audio_muted);

    return $output;
}

function set_mute($socket, $state) {
    if($state == "on" || $state === true || $state === 1) {
        $mute_state = true;
        $mute_set_command = hex2bin("0130413045304102303038443030303103090d");
    }elseif($state == "off" || $state === false || $state === 0) {
        $mute_state = false;
        $mute_set_command = hex2bin("01304130453041023030384430303032030a0d");
    }elseif($state == "toggle") {
        // get the current mute state and set it to the opposite
        $mute_data = get_mute_status($socket);

        $old_mute_status = $mute_data['audio_muted'];

        if($old_mute_status === false) {
            // if mute is off, turn it on
            $mute_state = 'true';
            $mute_set_command = hex2bin("0130413045304102303038443030303103090d");
        }elseif($old_mute_status === true) {
            // if mute is on, turn it off
            $mute_state = 'false';
            $mute_set_command = hex2bin("01304130453041023030384430303032030a0d");
        }
    }else {
        $output = array("audio_muted" => NULL, "error_num" => 30, "error_message" => "unknown mute command");
        return $output;
    }

    // send command to socket
    socket_write($socket, $mute_set_command, strlen($mute_set_command)) or die("832 - Could not send data to server\n");

    // get server response
    $response = socket_read($socket, 1024) or die("833 - Could not read server response\n");

    $raw_reply_from_nec = bin2hex($response);

    // print "raw reply from nec: " . $raw_reply_from_nec . "\n";

    // match the raw response we get from the NEC
    if($raw_reply_from_nec == "01303041463132023030303038443030303030323030303203790d") {
        $audio_muted = false;
    }elseif($raw_reply_from_nec == "013030414631320230303030384430303030303230303031037a0d") {
        $audio_muted = true;
    }else{
        $audio_muted = NULL;
    }

    $mute_return = array("audio_muted" => $audio_muted);

    return $mute_return;
}

// avert your eyes, this is a ugly hack
// takes the NEC response to a "get volume" command and convert it to the corresponding 0-100 int value
function volume_reply_to_int($raw_response) {
    preg_match("/0130304144313202303030303632303030303634(.{8})(.{6})/i", $raw_response, $matches);

    $volume_hex = $matches[1];

    switch ($volume_hex) {
        case '30303030':
            $volume_int = 0;
            break;
        case '30303031':
            $volume_int = 1;
            break;
        case '30303032':
            $volume_int = 2;
            break;
        case '30303033':
            $volume_int = 3;
            break;
        case '30303034':
            $volume_int = 4;
            break;
        case '30303035':
            $volume_int = 5;
            break;
        case '30303036':
            $volume_int = 6;
            break;
        case '30303037':
            $volume_int = 7;
            break;
        case '30303038':
            $volume_int = 8;
            break;
        case '30303039':
            $volume_int = 9;
            break;
        case '30303041':
            $volume_int = 10;
            break;
        case '30303042':
            $volume_int = 11;
            break;
        case '30303043':
            $volume_int = 12;
            break;
        case '30303044':
            $volume_int = 13;
            break;
        case '30303045':
            $volume_int = 14;
            break;
        case '30303046':
            $volume_int = 15;
            break;
        case '30303130':
            $volume_int = 16;
            break;
        case '30303131':
            $volume_int = 17;
            break;
        case '30303132':
            $volume_int = 18;
            break;
        case '30303133':
            $volume_int = 19;
            break;
        case '30303134':
            $volume_int = 20;
            break;
        case '30303135':
            $volume_int = 21;
            break;
        case '30303136':
            $volume_int = 22;
            break;
        case '30303137':
            $volume_int = 23;
            break;
        case '30303138':
            $volume_int = 24;
            break;
        case '30303139':
            $volume_int = 25;
            break;
        case '30303141':
            $volume_int = 26;
            break;
        case '30303142':
            $volume_int = 27;
            break;
        case '30303143':
            $volume_int = 28;
            break;
        case '30303144':
            $volume_int = 29;
            break;
        case '30303145':
            $volume_int = 30;
            break;
        case '30303146':
            $volume_int = 31;
            break;
        case '30303230':
            $volume_int = 32;
            break;
        case '30303231':
            $volume_int = 33;
            break;
        case '30303232':
            $volume_int = 34;
            break;
        case '30303233':
            $volume_int = 35;
            break;
        case '30303234':
            $volume_int = 36;
            break;
        case '30303235':
            $volume_int = 37;
            break;
        case '30303236':
            $volume_int = 38;
            break;
        case '30303237':
            $volume_int = 39;
            break;
        case '30303238':
            $volume_int = 40;
            break;
        case '30303239':
            $volume_int = 41;
            break;
        case '30303241':
            $volume_int = 42;
            break;
        case '30303242':
            $volume_int = 43;
            break;
        case '30303243':
            $volume_int = 44;
            break;
        case '30303244':
            $volume_int = 45;
            break;
        case '30303245':
            $volume_int = 46;
            break;
        case '30303246':
            $volume_int = 47;
            break;
        case '30303330':
            $volume_int = 48;
            break;
        case '30303331':
            $volume_int = 49;
            break;
        case '30303332':
            $volume_int = 50;
            break;
        case '30303333':
            $volume_int = 51;
            break;
        case '30303334':
            $volume_int = 52;
            break;
        case '30303335':
            $volume_int = 53;
            break;
        case '30303336':
            $volume_int = 54;
            break;
        case '30303337':
            $volume_int = 55;
            break;
        case '30303338':
            $volume_int = 56;
            break;
        case '30303339':
            $volume_int = 57;
            break;
        case '30303341':
            $volume_int = 58;
            break;
        case '30303342':
            $volume_int = 59;
            break;
        case '30303343':
            $volume_int = 60;
            break;
        case '30303344':
            $volume_int = 61;
            break;
        case '30303345':
            $volume_int = 62;
            break;
        case '30303346':
            $volume_int = 63;
            break;
        case '30303430':
            $volume_int = 64;
            break;
        case '30303431':
            $volume_int = 65;
            break;
        case '30303432':
            $volume_int = 66;
            break;
        case '30303433':
            $volume_int = 67;
            break;
        case '30303434':
            $volume_int = 68;
            break;
        case '30303435':
            $volume_int = 69;
            break;
        case '30303436':
            $volume_int = 70;
            break;
        case '30303437':
            $volume_int = 71;
            break;
        case '30303438':
            $volume_int = 72;
            break;
        case '30303439':
            $volume_int = 73;
            break;
        case '30303441':
            $volume_int = 74;
            break;
        case '30303442':
            $volume_int = 75;
            break;
        case '30303443':
            $volume_int = 76;
            break;
        case '30303444':
            $volume_int = 77;
            break;
        case '30303445':
            $volume_int = 78;
            break;
        case '30303446':
            $volume_int = 79;
            break;
        case '30303530':
            $volume_int = 80;
            break;
        case '30303531':
            $volume_int = 81;
            break;
        case '30303532':
            $volume_int = 82;
            break;
        case '30303533':
            $volume_int = 83;
            break;
        case '30303534':
            $volume_int = 84;
            break;
        case '30303535':
            $volume_int = 85;
            break;
        case '30303536':
            $volume_int = 86;
            break;
        case '30303537':
            $volume_int = 87;
            break;
        case '30303538':
            $volume_int = 88;
            break;
        case '30303539':
            $volume_int = 89;
            break;
        case '30303541':
            $volume_int = 90;
            break;
        case '30303542':
            $volume_int = 91;
            break;
        case '30303543':
            $volume_int = 92;
            break;
        case '30303544':
            $volume_int = 93;
            break;
        case '30303545':
            $volume_int = 94;
            break;
        case '30303546':
            $volume_int = 95;
            break;
        case '30303630':
            $volume_int = 96;
            break;
        case '30303631':
            $volume_int = 97;
            break;
        case '30303632':
            $volume_int = 98;
            break;
        case '30303633':
            $volume_int = 99;
            break;
        case '30303634':
            $volume_int = 100;
            break;
        default:
            $volume_int = NULL;
            break;
    }

    return $volume_int;
}

// more hackiness to avoid fully processing the command logic
// take an volume as an integer and convert it to the command string
function volume_set_value_mapper($volume_int) {
    // print "volume_int: " . $volume_int . "\n";

    switch ($volume_int) {
        case 0:
            $nec_volume_command = hex2bin("0130413045304102303036323030303003700d");
            break;
        case 1:
            $nec_volume_command = hex2bin("0130413045304102303036323030303103710d");
            break;
        case 2:
            $nec_volume_command = hex2bin("0130413045304102303036323030303203720d");
            break;
        case 3:
            $nec_volume_command = hex2bin("0130413045304102303036323030303303730d");
            break;
        case 4:
            $nec_volume_command = hex2bin("0130413045304102303036323030303403740d");
            break;
        case 5:
            $nec_volume_command = hex2bin("0130413045304102303036323030303503750d");
            break;
        case 6:
            $nec_volume_command = hex2bin("0130413045304102303036323030303603760d");
            break;
        case 7:
            $nec_volume_command = hex2bin("0130413045304102303036323030303703770d");
            break;
        case 8:
            $nec_volume_command = hex2bin("0130413045304102303036323030303803780d");
            break;
        case 9:
            $nec_volume_command = hex2bin("0130413045304102303036323030303903790d");
            break;
        case 10:
            $nec_volume_command = hex2bin("0130413045304102303036323030304103010d");
            break;
        case 11:
            $nec_volume_command = hex2bin("0130413045304102303036323030304203020d");
            break;
        case 12:
            $nec_volume_command = hex2bin("0130413045304102303036323030304303030d");
            break;
        case 13:
            $nec_volume_command = hex2bin("0130413045304102303036323030304403040d");
            break;
        case 14:
            $nec_volume_command = hex2bin("0130413045304102303036323030304503050d");
            break;
        case 15:
            $nec_volume_command = hex2bin("0130413045304102303036323030304603060d");
            break;
        case 16:
            $nec_volume_command = hex2bin("0130413045304102303036323030313003710d");
            break;
        case 17:
            $nec_volume_command = hex2bin("0130413045304102303036323030313103700d");
            break;
        case 18:
            $nec_volume_command = hex2bin("0130413045304102303036323030313203730d");
            break;
        case 19:
            $nec_volume_command = hex2bin("0130413045304102303036323030313303720d");
            break;
        case 20:
            $nec_volume_command = hex2bin("0130413045304102303036323030313403750d");
            break;
        case 21:
            $nec_volume_command = hex2bin("0130413045304102303036323030313503740d");
            break;
        case 22:
            $nec_volume_command = hex2bin("0130413045304102303036323030313603770d");
            break;
        case 23:
            $nec_volume_command = hex2bin("0130413045304102303036323030313703760d");
            break;
        case 24:
            $nec_volume_command = hex2bin("0130413045304102303036323030313803790d");
            break;
        case 25:
            $nec_volume_command = hex2bin("0130413045304102303036323030313903780d");
            break;
        case 26:
            $nec_volume_command = hex2bin("0130413045304102303036323030314103000d");
            break;
        case 27:
            $nec_volume_command = hex2bin("0130413045304102303036323030314203030d");
            break;
        case 28:
            $nec_volume_command = hex2bin("0130413045304102303036323030314303020d");
            break;
        case 29:
            $nec_volume_command = hex2bin("0130413045304102303036323030314403050d");
            break;
        case 30:
            $nec_volume_command = hex2bin("0130413045304102303036323030314503040d");
            break;
        case 31:
            $nec_volume_command = hex2bin("0130413045304102303036323030314603070d");
            break;
        case 32:
            $nec_volume_command = hex2bin("0130413045304102303036323030323003720d");
            break;
        case 33:
            $nec_volume_command = hex2bin("0130413045304102303036323030323103730d");
            break;
        case 34:
            $nec_volume_command = hex2bin("0130413045304102303036323030323203700d");
            break;
        case 35:
            $nec_volume_command = hex2bin("0130413045304102303036323030323303710d");
            break;
        case 36:
            $nec_volume_command = hex2bin("0130413045304102303036323030323403760d");
            break;
        case 37:
            $nec_volume_command = hex2bin("0130413045304102303036323030323503770d");
            break;
        case 38:
            $nec_volume_command = hex2bin("0130413045304102303036323030323603740d");
            break;
        case 39:
            $nec_volume_command = hex2bin("0130413045304102303036323030323703750d");
            break;
        case 40:
            $nec_volume_command = hex2bin("01304130453041023030363230303238037a0d");
            break;
        case 41:
            $nec_volume_command = hex2bin("01304130453041023030363230303239037b0d");
            break;
        case 42:
            $nec_volume_command = hex2bin("0130413045304102303036323030324103030d");
            break;
        case 43:
            $nec_volume_command = hex2bin("0130413045304102303036323030324203000d");
            break;
        case 44:
            $nec_volume_command = hex2bin("0130413045304102303036323030324303010d");
            break;
        case 45:
            $nec_volume_command = hex2bin("0130413045304102303036323030324403060d");
            break;
        case 46:
            $nec_volume_command = hex2bin("0130413045304102303036323030324503070d");
            break;
        case 47:
            $nec_volume_command = hex2bin("0130413045304102303036323030324603040d");
            break;
        case 48:
            $nec_volume_command = hex2bin("0130413045304102303036323030333003730d");
            break;
        case 49:
            $nec_volume_command = hex2bin("0130413045304102303036323030333103720d");
            break;
        case 50:
            $nec_volume_command = hex2bin("0130413045304102303036323030333203710d");
            break;
        case 51:
            $nec_volume_command = hex2bin("0130413045304102303036323030333303700d");
            break;
        case 52:
            $nec_volume_command = hex2bin("0130413045304102303036323030333403770d");
            break;
        case 53:
            $nec_volume_command = hex2bin("0130413045304102303036323030333503760d");
            break;
        case 54:
            $nec_volume_command = hex2bin("0130413045304102303036323030333603750d");
            break;
        case 55:
            $nec_volume_command = hex2bin("0130413045304102303036323030333703740d");
            break;
        case 56:
            $nec_volume_command = hex2bin("01304130453041023030363230303338037b0d");
            break;
        case 57:
            $nec_volume_command = hex2bin("01304130453041023030363230303339037a0d");
            break;
        case 58:
            $nec_volume_command = hex2bin("0130413045304102303036323030334103020d");
            break;
        case 59:
            $nec_volume_command = hex2bin("0130413045304102303036323030334203010d");
            break;
        case 60:
            $nec_volume_command = hex2bin("0130413045304102303036323030334303000d");
            break;
        case 61:
            $nec_volume_command = hex2bin("0130413045304102303036323030334403070d");
            break;
        case 62:
            $nec_volume_command = hex2bin("0130413045304102303036323030334503060d");
            break;
        case 63:
            $nec_volume_command = hex2bin("0130413045304102303036323030334603050d");
            break;
        case 64:
            $nec_volume_command = hex2bin("0130413045304102303036323030343003740d");
            break;
        case 65:
            $nec_volume_command = hex2bin("0130413045304102303036323030343103750d");
            break;
        case 66:
            $nec_volume_command = hex2bin("0130413045304102303036323030343203760d");
            break;
        case 67:
            $nec_volume_command = hex2bin("0130413045304102303036323030343303770d");
            break;
        case 68:
            $nec_volume_command = hex2bin("0130413045304102303036323030343403700d");
            break;
        case 69:
            $nec_volume_command = hex2bin("0130413045304102303036323030343503710d");
            break;
        case 70:
            $nec_volume_command = hex2bin("0130413045304102303036323030343603720d");
            break;
        case 71:
            $nec_volume_command = hex2bin("0130413045304102303036323030343703730d");
            break;
        case 72:
            $nec_volume_command = hex2bin("01304130453041023030363230303438037c0d");
            break;
        case 73:
            $nec_volume_command = hex2bin("01304130453041023030363230303439037d0d");
            break;
        case 74:
            $nec_volume_command = hex2bin("0130413045304102303036323030344103050d");
            break;
        case 75:
            $nec_volume_command = hex2bin("0130413045304102303036323030344203060d");
            break;
        case 76:
            $nec_volume_command = hex2bin("0130413045304102303036323030344303070d");
            break;
        case 77:
            $nec_volume_command = hex2bin("0130413045304102303036323030344403000d");
            break;
        case 78:
            $nec_volume_command = hex2bin("0130413045304102303036323030344503010d");
            break;
        case 79:
            $nec_volume_command = hex2bin("0130413045304102303036323030344603020d");
            break;
        case 80:
            $nec_volume_command = hex2bin("0130413045304102303036323030353003750d");
            break;
        case 81:
            $nec_volume_command = hex2bin("0130413045304102303036323030353103740d");
            break;
        case 82:
            $nec_volume_command = hex2bin("0130413045304102303036323030353203770d");
            break;
        case 83:
            $nec_volume_command = hex2bin("0130413045304102303036323030353303760d");
            break;
        case 84:
            $nec_volume_command = hex2bin("0130413045304102303036323030353403710d");
            break;
        case 85:
            $nec_volume_command = hex2bin("0130413045304102303036323030353503700d");
            break;
        case 86:
            $nec_volume_command = hex2bin("0130413045304102303036323030353603730d");
            break;
        case 87:
            $nec_volume_command = hex2bin("0130413045304102303036323030353703720d");
            break;
        case 88:
            $nec_volume_command = hex2bin("01304130453041023030363230303538037d0d");
            break;
        case 89:
            $nec_volume_command = hex2bin("01304130453041023030363230303539037c0d");
            break;
        case 90:
            $nec_volume_command = hex2bin("0130413045304102303036323030354103040d");
            break;
        case 91:
            $nec_volume_command = hex2bin("0130413045304102303036323030354203070d");
            break;
        case 92:
            $nec_volume_command = hex2bin("0130413045304102303036323030354303060d");
            break;
        case 93:
            $nec_volume_command = hex2bin("0130413045304102303036323030354403010d");
            break;
        case 94:
            $nec_volume_command = hex2bin("0130413045304102303036323030354503000d");
            break;
        case 95:
            $nec_volume_command = hex2bin("0130413045304102303036323030354603030d");
            break;
        case 96:
            $nec_volume_command = hex2bin("0130413045304102303036323030363003760d");
            break;
        case 97:
            $nec_volume_command = hex2bin("0130413045304102303036323030363103770d");
            break;
        case 98:
            $nec_volume_command = hex2bin("0130413045304102303036323030363203740d");
            break;
        case 99:
            $nec_volume_command = hex2bin("0130413045304102303036323030363303750d");
            break;
        case 100:
            $nec_volume_command = hex2bin("0130413045304102303036323030363403720d");
            break;
        default:
            $nec_volume_command = NULL;
            break;
    }

    return $nec_volume_command;
}

// when you set a volume, you'll get a raw reply
// this function will map that reply to a volume integer
function set_volume_response_mapper($reply) {
    switch ($reply) {
        case '01303041463132023030303036323030303036343030303003030d':
            $volume = 0;
            break;
        case '01303041463132023030303036323030303036343030303103020d':
            $volume = 1;
            break;
        case '01303041463132023030303036323030303036343030303203010d':
            $volume = 2;
            break;
        case '01303041463132023030303036323030303036343030303303000d':
            $volume = 3;
            break;
        case '01303041463132023030303036323030303036343030303403070d':
            $volume = 4;
            break;
        case '01303041463132023030303036323030303036343030303503060d':
            $volume = 5;
            break;
        case '01303041463132023030303036323030303036343030303603050d':
            $volume = 6;
            break;
        case '01303041463132023030303036323030303036343030303703040d':
            $volume = 7;
            break;
        case '013030414631320230303030363230303030363430303038030b0d':
            $volume = 8;
            break;
        case '013030414631320230303030363230303030363430303039030a0d':
            $volume = 9;
            break;
        case '01303041463132023030303036323030303036343030304103720d':
            $volume = 10;
            break;
        case '01303041463132023030303036323030303036343030304203710d':
            $volume = 11;
            break;
        case '01303041463132023030303036323030303036343030304303700d':
            $volume = 12;
            break;
        case '01303041463132023030303036323030303036343030304403770d':
            $volume = 13;
            break;
        case '01303041463132023030303036323030303036343030304503760d':
            $volume = 14;
            break;
        case '01303041463132023030303036323030303036343030304603750d':
            $volume = 15;
            break;
        case '01303041463132023030303036323030303036343030313003020d':
            $volume = 16;
            break;
        case '01303041463132023030303036323030303036343030313103030d':
            $volume = 17;
            break;
        case '01303041463132023030303036323030303036343030313203000d':
            $volume = 18;
            break;
        case '01303041463132023030303036323030303036343030313303010d':
            $volume = 19;
            break;
        case '01303041463132023030303036323030303036343030313403060d':
            $volume = 20;
            break;
        case '01303041463132023030303036323030303036343030313503070d':
            $volume = 21;
            break;
        case '01303041463132023030303036323030303036343030313603040d':
            $volume = 22;
            break;
        case '01303041463132023030303036323030303036343030313703050d':
            $volume = 23;
            break;
        case '013030414631320230303030363230303030363430303138030a0d':
            $volume = 24;
            break;
        case '013030414631320230303030363230303030363430303139030b0d':
            $volume = 25;
            break;
        case '01303041463132023030303036323030303036343030314103730d':
            $volume = 26;
            break;
        case '01303041463132023030303036323030303036343030314203700d':
            $volume = 27;
            break;
        case '01303041463132023030303036323030303036343030314303710d':
            $volume = 28;
            break;
        case '01303041463132023030303036323030303036343030314403760d':
            $volume = 29;
            break;
        case '01303041463132023030303036323030303036343030314503770d':
            $volume = 30;
            break;
        case '01303041463132023030303036323030303036343030314603740d':
            $volume = 31;
            break;
        case '01303041463132023030303036323030303036343030323003010d':
            $volume = 32;
            break;
        case '01303041463132023030303036323030303036343030323103000d':
            $volume = 33;
            break;
        case '01303041463132023030303036323030303036343030323203030d':
            $volume = 34;
            break;
        case '01303041463132023030303036323030303036343030323303020d':
            $volume = 35;
            break;
        case '01303041463132023030303036323030303036343030323403050d':
            $volume = 36;
            break;
        case '01303041463132023030303036323030303036343030323503040d':
            $volume = 37;
            break;
        case '01303041463132023030303036323030303036343030323603070d':
            $volume = 38;
            break;
        case '01303041463132023030303036323030303036343030323703060d':
            $volume = 39;
            break;
        case '01303041463132023030303036323030303036343030323803090d':
            $volume = 40;
            break;
        case '01303041463132023030303036323030303036343030323903080d':
            $volume = 41;
            break;
        case '01303041463132023030303036323030303036343030324103700d':
            $volume = 42;
            break;
        case '01303041463132023030303036323030303036343030324203730d':
            $volume = 43;
            break;
        case '01303041463132023030303036323030303036343030324303720d':
            $volume = 44;
            break;
        case '01303041463132023030303036323030303036343030324403750d':
            $volume = 45;
            break;
        case '01303041463132023030303036323030303036343030324503740d':
            $volume = 46;
            break;
        case '01303041463132023030303036323030303036343030324603770d':
            $volume = 47;
            break;
        case '01303041463132023030303036323030303036343030333003000d':
            $volume = 48;
            break;
        case '01303041463132023030303036323030303036343030333103010d':
            $volume = 49;
            break;
        case '01303041463132023030303036323030303036343030333203020d':
            $volume = 50;
            break;
        case '01303041463132023030303036323030303036343030333303030d':
            $volume = 51;
            break;
        case '01303041463132023030303036323030303036343030333403040d':
            $volume = 52;
            break;
        case '01303041463132023030303036323030303036343030333503050d':
            $volume = 53;
            break;
        case '01303041463132023030303036323030303036343030333603060d':
            $volume = 54;
            break;
        case '01303041463132023030303036323030303036343030333703070d':
            $volume = 55;
            break;
        case '01303041463132023030303036323030303036343030333803080d':
            $volume = 56;
            break;
        case '01303041463132023030303036323030303036343030333903090d':
            $volume = 57;
            break;
        case '01303041463132023030303036323030303036343030334103710d':
            $volume = 58;
            break;
        case '01303041463132023030303036323030303036343030334203720d':
            $volume = 59;
            break;
        case '01303041463132023030303036323030303036343030334303730d':
            $volume = 60;
            break;
        case '01303041463132023030303036323030303036343030334403740d':
            $volume = 61;
            break;
        case '01303041463132023030303036323030303036343030334503750d':
            $volume = 62;
            break;
        case '01303041463132023030303036323030303036343030334603760d':
            $volume = 63;
            break;
        case '01303041463132023030303036323030303036343030343003070d':
            $volume = 64;
            break;
        case '01303041463132023030303036323030303036343030343103060d':
            $volume = 65;
            break;
        case '01303041463132023030303036323030303036343030343203050d':
            $volume = 66;
            break;
        case '01303041463132023030303036323030303036343030343303040d':
            $volume = 67;
            break;
        case '01303041463132023030303036323030303036343030343403030d':
            $volume = 68;
            break;
        case '01303041463132023030303036323030303036343030343503020d':
            $volume = 69;
            break;
        case '01303041463132023030303036323030303036343030343603010d':
            $volume = 70;
            break;
        case '01303041463132023030303036323030303036343030343703000d':
            $volume = 71;
            break;
        case '013030414631320230303030363230303030363430303438030f0d':
            $volume = 72;
            break;
        case '013030414631320230303030363230303030363430303439030e0d':
            $volume = 73;
            break;
        case '01303041463132023030303036323030303036343030344103760d':
            $volume = 74;
            break;
        case '01303041463132023030303036323030303036343030344203750d':
            $volume = 75;
            break;
        case '01303041463132023030303036323030303036343030344303740d':
            $volume = 76;
            break;
        case '01303041463132023030303036323030303036343030344403730d':
            $volume = 77;
            break;
        case '01303041463132023030303036323030303036343030344503720d':
            $volume = 78;
            break;
        case '01303041463132023030303036323030303036343030344603710d':
            $volume = 79;
            break;
        case '01303041463132023030303036323030303036343030353003060d':
            $volume = 80;
            break;
        case '01303041463132023030303036323030303036343030353103070d':
            $volume = 81;
            break;
        case '01303041463132023030303036323030303036343030353203040d':
            $volume = 82;
            break;
        case '01303041463132023030303036323030303036343030353303050d':
            $volume = 83;
            break;
        case '01303041463132023030303036323030303036343030353403020d':
            $volume = 84;
            break;
        case '01303041463132023030303036323030303036343030353503030d':
            $volume = 85;
            break;
        case '01303041463132023030303036323030303036343030353603000d':
            $volume = 86;
            break;
        case '01303041463132023030303036323030303036343030353703010d':
            $volume = 87;
            break;
        case '013030414631320230303030363230303030363430303538030e0d':
            $volume = 88;
            break;
        case '013030414631320230303030363230303030363430303539030f0d':
            $volume = 89;
            break;
        case '01303041463132023030303036323030303036343030354103770d':
            $volume = 90;
            break;
        case '01303041463132023030303036323030303036343030354203740d':
            $volume = 91;
            break;
        case '01303041463132023030303036323030303036343030354303750d':
            $volume = 92;
            break;
        case '01303041463132023030303036323030303036343030354403720d':
            $volume = 93;
            break;
        case '01303041463132023030303036323030303036343030354503730d':
            $volume = 94;
            break;
        case '01303041463132023030303036323030303036343030354603700d':
            $volume = 95;
            break;
        case '01303041463132023030303036323030303036343030363003050d':
            $volume = 96;
            break;
        case '01303041463132023030303036323030303036343030363103040d':
            $volume = 97;
            break;
        case '01303041463132023030303036323030303036343030363203070d':
            $volume = 98;
            break;
        case '01303041463132023030303036323030303036343030363303060d':
            $volume = 99;
            break;
        case '01303041463132023030303036323030303036343030363403010d':
            $volume = 100;
            break;
        default:
            $volume = NULL;
            break;
    }

    return $volume;
}
?>
