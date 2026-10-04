# HomeAssistant

Work in progress!!!!!!!!!!!!!!

## Requirements

You need to install:

* php-cli
* php-curl
* php-json

```shell
# Alpine Linux
apk add php-cli php-curl php-json
```

```shell
# Debian based
apt install -y php-cli php-curl php-json
```

## Setup

> [NOTE]
> Since the script caches the JSON file, we can implement the fetch as different sensors, without the cache the
> API would have been hit once per sensor. A 5-minute refresh uses 12 of the 20 API calls per hour.

```yaml
skoda_base_cmd: >
  SKODA_VIN='TMBNG123456789012'
  SKODA_KEY='msk_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
  php /config/scripts/skodaLnxCmd.php json
skoda_ac_cmd: >
  SKODA_VIN='TMBNG123456789012'
  SKODA_KEY='msk_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
  php /config/scripts/skodaLnxCmd.php ac
skoda_ac_off_cmd: >
  SKODA_VIN='TMBNG123456789012'
  SKODA_KEY='msk_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
  php /config/scripts/skodaLnxCmd.php reset
```


```yaml
shell_command:
  skoda_ac_on: !secret skoda_ac_cmd
  skoda_ac_off: !secret skoda_ac_off_cmd
command_line:
  - sensor:
      name: "Skoda State of Charge"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.soc }}"
      unit_of_measurement: "%"
      icon: "mdi:car-electric"

  - sensor:
      name: "Skoda Lock Status"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.locked }}"
      icon: "mdi:car-door-lock"

  - sensor:
      name: "Skoda Odometer"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.odometer }}"
      unit_of_measurement: "km"
      icon: "mdi:counter"

  - sensor:
      name: "Skoda Range"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.range }}"
      unit_of_measurement: "km"
      icon: "mdi:gauge"

  - sensor:
      name: "Skoda Parking Location"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.parking_location }}"
      icon: "mdi:map-marker"

  - sensor:
      name: "Skoda Charge Completion"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.charge_done }}"
      device_class: timestamp
      icon: "mdi:clock-check-outline"

  - sensor:
      name: "Skoda Charging Power"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.charge_power | float(0) }}"
      unit_of_measurement: "kW"
      device_class: power
      state_class: measurement
      icon: "mdi:lightning-bolt"

  - sensor:
      name: "Skoda Charging Rate"
      command: !secret skoda_base_cmd
      scan_interval: 300
      value_template: "{{ value_json.charge_rate | float(0) }}"
      unit_of_measurement: "km/h"
      state_class: measurement
      icon: "mdi:speedometer"
```

Note: This document has been created with the assistance of Googles AI.


