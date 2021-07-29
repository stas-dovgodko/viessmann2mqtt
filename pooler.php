<?php require __DIR__ . '/vendor/autoload.php';
session_start(); // viesmann api related
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Viessmann\API\ViessmannAPI;
use Viessmann\API\ViessmannFeature;

$error = function($message) {
    echo $message;
    error_log($message);
};

try {
    $dotenv = Dotenv\Dotenv::createImmutable(getcwd());
    $dotenv->load();

    $mqttClient = new MqttClient(getenv('MQTT_HOST') ?: '127.0.0.1', getenv('MQTT_PORT') ?: 1883, getenv('MQTT_CLIENT_ID') ?: 'viessmannpooler');

    $username = getenv('MQTT_USERNAME');
    $password = getenv('MQTT_PASSWORD');

    if ($username || $password) {
        $settings = (new ConnectionSettings())
            ->setUsername($username)
            ->setPassword($password);
    } else {
        $settings = null;
    }

    $is_help = in_array(@$argv[1], ['h', '-h', 'help', '--h', '-help', '--help']);

    $is_get = in_array(@$argv[1], ['get']);
    $is_set = in_array(@$argv[1], ['set']);

    $is_mqtt = !($is_help || $is_set || $is_get);

    if ($is_mqtt) {
        $mqttClient->connect($settings);
    }

    $qos = getenv('MQTT_QOS') ?: MQTTClient::QOS_AT_MOST_ONCE;
    $seconds = getenv('POOL_SECONDS') ?: 60;

    $publish = function ($topic, $data) use ($mqttClient, $qos, $is_help, $is_mqtt) {
        /** @var $mqttClient MqttClient */

        $topic = 'viessmann/' . $topic;
        if ($is_help) {
            echo '-> '.$topic.'='.json_encode($data)."\n";
            return;
        } elseif ($is_mqtt) return $mqttClient->publish($topic, \json_encode($data), $qos);
    };

    error_reporting(E_ERROR); ini_set("display_errors", 1);
    $viessmannApi = new ViessmannAPI([
        "user" => trim(getenv('VIESSMANN_USERNAME')),
        "pwd" => trim(getenv('VIESSMANN_PASSWORD')),
        "clientId" => trim(getenv('VIESSMANN_CLIENTID')),
    ], true);


    $devices = [];
    foreach(@json_decode($viessmannApi->getGatewayFeatures(), true)['data'] as $item) {

        $map = [];
        if($item['properties']) foreach($item['properties'] as $property_name => $property_data) {
            $map[$property_name] = $property_data['value'];

        }
        if (!$item['components']) $publish('gateway/'.$item['feature'], $map);

        if ($item['feature'] === 'gateway.devices') {
            foreach($map['devices'] as $device_info) $devices[] = $device_info['id'];
        }
    }

    $methods = [

    ];

    $flat_properties = [];
    foreach ($devices as $deviceId) {
        foreach(@json_decode($viessmannApi->getDeviceFeatures($deviceId), true)['data'] as $item) {
            $map = [];
            $method = $deviceId.'/'.$item['feature'];
            if($item['properties']) foreach($item['properties'] as $property_name => $property_data) {
                $map[$property_name] = $property_data['value'];
                $flat_properties[$method.'@'.$property_name] = $property_data['value'];
            }

            if (!$item['components']) $publish($method, $map);

            $methods[$method] = [];

            if ($item['commands']) foreach($item['commands'] as $command_name => $command) {

                $methods[$method][$command_name] = [];
                foreach ((array)$command['params'] as $field_name => $field) {
                    $methods[$method][$command_name][$field_name] = $field['type'];
                }
            }
        }

    }

    if ($methods) {
        if ($is_help) {
            $i=0;
            foreach ($methods as $property => $property_methods) {
                foreach ($property_methods as $method_name => $method_args) {
                    echo '<- viessmann/'.$property.'@'.$method_name;
                    echo '('.json_encode($method_args).')';
                    echo "\n";
                }
            }
        } elseif ($is_get) {
            $key = @$argv[2];
            if (array_key_exists($key, $flat_properties)) {
                echo json_encode($flat_properties[$key]);
            } else {
                $error('Unsupported feature - '.$key);
            }
        } elseif ($is_set) {
            $key = @$argv[2]; $message = @$argv[3];
            list($property, $method_name) = explode('@', $key);
            list($device, $feature) = explode('/', $property);

            if (isset($methods[$property][$method_name])) {
                $viessmannApi->commandDeviceFeature($device, $feature, $method_name, $message);
            } else {
                $error('Unsupported feature - '.$property.'#'.$method_name);
            }
        } else {


            $mqttClient->registerLoopEventHandler(function (MQTTClient $mqttClient, $elapsedTime) use ($seconds) {
                if ($elapsedTime >= $seconds) $mqttClient->interrupt();
                else echo '.';
            });
            $mqttClient->subscribe('viessmann/+/+/+', function ($topic, $message) use ($viessmannApi, $methods, $error) {
                if (($pos = strpos($topic, $pref = 'viessmann/')) !== false) {
                    list($device, $feature, $method) = explode('/', substr($topic, $pos + strlen($pref)));

                    $property = $device.'/'.$feature;
                    if (isset($methods[$property][$method])) {
                        $viessmannApi->commandDeviceFeature($device, $feature, $method, $message);
                        echo $property.'@'.$method . ': ' . $message . PHP_EOL;
                    } else {
                        $error('Unsupported feature - '.$property.'#'.$method);
                    }


                }
            });

            $mqttClient->loop(true, true, $seconds);

        }
    }
} catch (\Exception $e) {
    $error($e->getMessage());
}