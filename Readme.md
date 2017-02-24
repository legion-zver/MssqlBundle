Installation
-------
### Step 1. Install MssqlBundle
Add the **realestate/mssql-bundle** into **composer.json**

    "require": {
        ....
        "realestateconz/mssql-bundle": "master-dev"
    },

And run

``` bash
$ php composer.phar install
```

Add to parameters.yml
    
    database_type: mssql
    
### Step 2. Configure DBAL's connection to use MssqlBundle
In config.yml, remove the "driver" param and add "driver_class" instead:

```
doctrine:
    dbal:
        default_connection:     default
        connections:
            default:
                driver_class:   Realestate\MssqlBundle\Driver\PDODblib\Driver
                host:           %database_host%
                dbname:         %database_prefix%%database_name%
                user:           %database_user%
                password:       %database_password%
```

### Step 3. Enable the bundle
Finally, enable the bundle in the kernel:

``` php
<?php
// app/AppKernel.php

public function registerBundles()
{
    $bundles = array(
        // ...
        new Realestate\MssqlBundle\RealestateMssqlBundle(),
    );
}
```

Notes
-------
### Prerequsites
This driver requires version 8.0 (from http://www.ubuntitis.com/?p=64) as default 4.2 version does not have UTF support

In /etc/freetds/freetds.conf, change
tds version = 4.2
to
tds version = 8.0

### NVARCHAR & NTEXT data types ( INSERT / UPDATE SQL)

Add Types For Add 'N' to Update / Insert Requests



