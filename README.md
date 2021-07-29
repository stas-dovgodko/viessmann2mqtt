# viessmann2mqtt

PHP based viessmann API to mqtt gateway.

For IoT diy stuff to upload and visualize data in grafana

Please add local config just like _.env.example_ somewhere near and run:
 
 `php pooler.phar`

Can use plain pooler.php instead of phar too

Config example:

```
VIESSMANN_USERNAME=user@example.com
VIESSMANN_PASSWORD=password

MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_QOS=2
MQTT_USERNAME=
MQTT_PASSWORD=
MQTT_CLIENT_ID=viessmannpooler
MQTT_DEBUG_FILE=debug.log


POOL_SECONDS=60
```


## MQTT topics

Check `viessmann/feature/#` to get|put data 

```
php pooler.phar -h
```

## CLI Mode

Set data to API via CLI

```
php pooler.php set 0/heating.circuits.0.temperature.levels@setMax '{"temperature":25}'
```

Get data from API via CLI

```
php pooler.php get 0/heating.circuits.0.temperature.levels@max
> 45
```
