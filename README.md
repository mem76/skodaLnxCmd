# SkodaLnxCmd - Škoda Linux Terminal application

This application is a command line tool to get status, pause and resume charging, and start the AC (heating/cooling).

The Application uses the [MyŠkoda Public API](https://public.api.connect.skoda-auto.cz/docs) (released early fall 2026), in order for this application to work you have to generate an API key via the `MySkoda app`.

This application allows you to:
* Start the AC from command line
* Start the AC or charging via a cron job

## Technology

This application use PHP 8.3 or newer with the `php-curl`, `php-json`. The code has no additional dependency. 

## Setup

### Install dependencies 

```shell
# In Ubuntu and Debian
sudo apt install php-cli php-json php-curl
```

The above installation will most likely also work MS Windows with `Windows Subsystem for Linux (WSL)`.

### Installation

1. Download [skodaLnxCmd.php](skodaLnxCmd.php).
2. Save it to a directory that is in $PATH (like ~/bin)
3. Make it executable `chmod a+x ~/bin/skodaLnxCmd.php`

```bash
# Some distro does not have ~/bin/ in $PATH as default.
# Make sure that ~/.profile contains:
if [ -d "$HOME/bin" ] ; then
    PATH="$HOME/bin:$PATH"
fi
```

### Configuration
The script needs to know you VIN number and API key. You can add them
* In the config file `$HOME/.config/com.github.com.mem76.skodaLnxCmd/config.conf`
* As environment variables `SKODA_VIN` and `SKODA_KEY`.

In the config file you can also
* Set a pin
* Change cache timeout length

```shell
#Sample config file
SKODA_VIN='TMBNG123456789012'
SKODA_KEY='msk_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
#SKODA_PIN=
```

## Usage

```
skodaLnxCmd.php status
##############################[EP12345]###############################
# ODO:    5853 km     # Not charging                                 #
# SOC:    78%         #                                              #
# Range:  293 km      #                                              #
# Locked: YES         #                                              #
######################################################################
# Parked: MyStreetxxxxx 42B, xxxxxxxxxxxxx, Norway                   #
# AC: On, target: 20°C (HEATING)                                     #
######################################################################
# Rate limit: 18/20 (19 minutes left)                                #
# Key expires: 2027-02-26T10:04:54.47Z                               #
######################################################################
```

```
skodeLnxCmd.php status
##############################[EP12345]###############################
# ODO:    5853 km     # Is charging                                  #
# SOC:    70%         # Charging power: 1.9 kW                       #
# Range:  263 km      # Sharing rate:   13 km/h                      #
# Locked: YES         # Done in: 55 minutes                          #
######################################################################
# Parked: MyStreetxxxxx 42B, xxxxxxxxxxxxx, Norway                   #
######################################################################
# Rate limit: 18/20 (51 minutes left)                                #
# Key expires: 2027-02-26T10:04:54.47Z                               #
######################################################################

```

```
./skodaLnxCmd.php json | jq
{
  "charge_done": null,
  "charge_power": null,
  "charge_rate": null,
  "locked": "YES",
  "odometer": 5853,
  "parking_location": "MyStreetxxxxx 42B, xxxxxxxxxxxxx, Norway",
  "range": 293,
  "soc": 78
}
```

```
./skodaLnxCmd.php ac
Air conditioning has been started
```

```
./skodaLnxCmd.php ac-off
Air conditioning has been stopped.
```

```
./skodaLnxCmd.php charge
Car not plugged inn? Trying in case of stale data.
Charging has been started.
```

```
./skodaLnxCmd.php support
Vehicle claim to support:
* startCharging
* stopCharging
* setChargingLimit
* setChargeMode
* updateChargingProfile
* startAirConditioning
* stopAirConditioning

Charge modes: 
* MANUAL
```