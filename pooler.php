<?php require __DIR__ . '/vendor/autoload.php';
session_start(); // viesmann api related
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use Viessmann\API\ViessmannAPI;

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

    if (!$is_help) {
        $mqttClient->connect($settings);
    }

    $qos = getenv('MQTT_QOS') ?: MQTTClient::QOS_AT_MOST_ONCE;
    $seconds = getenv('POOL_SECONDS') ?: 60;

    $publish = function ($topic, $data) use ($mqttClient, $qos, $is_help) {
        /** @var $mqttClient MqttClient */

        $topic = 'viessmann/' . $topic;
        if ($is_help) {
            echo '-> '.$topic.'='.json_encode($data)."\n";
            return;
        }
        return $mqttClient->publish($topic, \json_encode($data), $qos);
    };

    $viessmannApi = new ViessmannAPI([
        "user" => trim(getenv('VIESSMANN_USERNAME')),
        "pwd" => trim(getenv('VIESSMANN_PASSWORD'))
    ]);

    $features = array_map('trim', explode(',', $viessmannApi->getAvailableFeatures()));

    $publish('features', $features);

    $methods = [];

    foreach($features as $property) {
        $data = json_decode($viessmannApi->getRawJsonData($property), true);

        $map = [];
        if (array_key_exists('properties', $data)) foreach (@$data['properties'] as $n => $info)
        {
            $map[$n] = $info['value'];
        }
        if ($map) $publish('feature/'.$property, $map);


        if (array_key_exists('actions', $data)) {
            $methods[$property] = [];

            foreach(@$data['actions'] as $info) {

                $methods[$property][$info['name']] = [];
                //echo '  @'.$info['name']."(";
                foreach ((array)@$info['fields'] as $info2) {
                    $methods[$property][$info['name']][$info2['name']] = $info2['type'];
                }
            }
        }
    }

    if ($methods) {
        if ($is_help) {
            $i=0;
            foreach ($methods as $property => $property_methods) {
                foreach ($property_methods as $method_name => $method_args) {
                    echo '<- viessmann/feature/'.$property.'/'.$method_name;
                    echo '('.json_encode($method_args).')';
                    echo "\n";
                }
            }
        } else {


            $mqttClient->registerLoopEventHandler(function (MQTTClient $mqttClient, $elapsedTime) use ($seconds) {
                if ($elapsedTime >= $seconds) $mqttClient->interrupt();
                else echo '.';
            });
            $mqttClient->subscribe('viessmann/feature/+/+', function ($topic, $message) use ($viessmannApi, $methods, $error) {
                if (($pos = strpos($topic, $pref = 'viessmann/feature/')) !== false) {
                    list($property, $method) = explode('/', substr($topic, $pos + strlen($pref)));

                    if (isset($methods[$property][$method])) {
                        $viessmannApi->setRawJsonData($property, $method, $message);
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