<p align="center">
    <img width="400" height="125" src="https://raw.githubusercontent.com/elgentos/masquerade/master/art/logo.png" alt="Masquerade logo" />
</p>

# Masquerade

## Faker-driven, platform-agnostic, locale-compatible data faker tool

Point Masquerade to a database, give it a rule-set defined in YAML and Masquerade will anonymize the data for you
 automatically!

<img src="https://user-images.githubusercontent.com/431360/42574650-30e8d186-851f-11e8-9693-c23b426c43f2.png" width="600" />

### Out-of-the-box supported frameworks

- Magento 2

### Customization

You can add your own configuration files in a directory named `config` in the same directory as where you run masquerade. The configuration files will be merged with any already present configuration files for that platform, overriding any out-of-the-box values.

See the [Magento 2 YAML files](https://github.com/elgentos/masquerade/tree/master/src/config/magento2) as examples for notation.

For example, to override the `admin.yaml` for Magento 2, you place a file in `config/magento2/admin.yaml`. For example, if you want to completely disable/skip a group, just add this content;

```
admin:
```

### Partial anonymisation

You can affect only certain records by including a 'where' clause - for example to avoid anonymising certain admin accounts, or to preserve data used in unit tests, like this:

```yaml
customers:
  customer_entity:
    provider: # this sets options specific to the type of table
      where: "`email` not like '%@mycompany.com'" # leave mycompany.com emails alone
```

### Delete Data

You might want to fully or partially delete data - eg. if your developers don't need sales orders, or you want to keep the database size a lot smaller than the production database.  Specify the 'delete' option.

When deleting some Magento data, eg. sales orders, add the command line option `--with-integrity` which enforces foreign key checks, so for example sales\_invoice records will be deleted automatically if their parent sales\_order is deleted:

```yaml
orders:
  sales_order:
    provider:
      delete: true
      where: "customer_id != 3" # delete all except customer 3's orders because we use that for testing
    # no need to specify columns if you're using 'delete'      
```

If you use 'delete' without a 'where', and without '--with-integrity', it will use 'truncate' to delete the entire table.  It will not use truncate if --with-integrity is specified since that bypasses key checks.

### Magento EAV Attributes

You can use the Magento2Eav table type to treat EAV attributes just like normal columns, eg.

```yaml
products:
  catalog_product_entity: # specify the base table of the entity
    eav: true
    provider:
      where: "sku != 'TESTPRODUCT'" # you can still use 'where' and 'delete'
    columns:
      my_custom_attribute:
        formatter: sentence
      my_other_attribute:
        formatter: email

  catalog_category_entity:
    eav: true
    columns:
      description: # refer to EAV attributes like normal columns
        formatter: paragraph

```

### Formatter Options

For formatters, you can use all default [Faker formatters](https://github.com/fzaninotto/Faker#formatters).

#### Custom Data Providers / Formatters

You can also create your own custom providers with formatters. They need to extend `Faker\Provider\Base` and they need to live in either `~/.masquerade` or `.masquerade` relative from where you run masquerade.

An example file `.masquerade/Custom/WoopFormatter.php`;

```php
<?php

namespace Custom;

use Faker\Provider\Base;

class WoopFormatter extends Base {

    public function woopwoop() {
        $woops = ['woop', 'wop', 'wopwop', 'woopwoop'];
        return $woops[array_rand($woops)];
    }
}
```

And then use it in your YAML file. A provider needs to be set on the column name level, not on the formatter level.

```
customer:
  customer_entity:
    columns:
      firstname:
        provider: \Custom\WoopFormatter
        formatter:
          name: woopwoop
```

### Custom Table Type Providers

Some systems have linked tables containing related data - eg. Magento's EAV system, Drupal's entity fields and Wordpress's post metadata tables.  You can provide custom table types. 
In order to do it you need to implement 2 interfaces:
 - `Elgentos\Masquerade\DataProcessorFactory` is to instantiate your custom processor. It receives table service factory, output object and whole array of yaml configuration specified for your table.
 - `Elgentos\Masquerade\DataProcessor` is to process various operations required by run command like:

   - `truncate` should truncate table in provided table via configuration
   - `delete` should delete table in provided table via configuration
   - `updateTable` should update table with values provided by generator based on columns definitions in the configuration. 
     See `Elgentos\Masquerade\DataProcessor\RegularTableProcessor::updateTable` for a reference.


First you need to start with a factory that will instantiate an actual processor

An example file `.masquerade/Custom/WoopTableFactory.php`;
```php
<?php

namespace Custom;

use Elgentos\Masquerade\DataProcessor;
use Elgentos\Masquerade\DataProcessor\TableServiceFactory;
use Elgentos\Masquerade\DataProcessorFactory;
use Elgentos\Masquerade\Output;
 
class WoopTableFactory implements DataProcessorFactory 
{

    public function create(
        Output $output, 
        TableServiceFactory $tableServiceFactory,
        array $tableConfiguration
    ): DataProcessor {
        $tableService = $tableServiceFactory->create($tableConfiguration['name']);

        return new WoopTable($output, $tableService, $tableConfiguration);
    }
}
```

An example file `.masquerade/Custom/WoopTable.php`;

```php
<?php

namespace Custom;

use Elgentos\Masquerade\DataProcessor;
use Elgentos\Masquerade\DataProcessor\TableService;
use Elgentos\Masquerade\Output;

class WoopTable implements DataProcessor
{
    /** @var Output */
    private $output;

    /** @var array */
    private $configuration;

    /** @var TableService */
    private $tableService;

    public function __construct(Output $output, TableService $tableService, array $configuration)
    {
        $this->output = $output;
        $this->tableService = $tableService;
        $this->configuration = $configuration;
    }

    public function truncate(): void
    {
        $this->tableService->truncate();
    }
    
    public function delete(): void
    {
        $this->tableService->delete($this->configuration['provider']['where'] ?? '');
    }
    
    public function updateTable(int $batchSize, callable $generator): void
    {
        $columns = $this->tableService->filterColumns($this->configuration['columns'] ?? []);
        $primaryKey = $this->configuration['pk'] ?? $this->tableService->getPrimaryKey();
        
        $this->tableService->updateTable(
            $columns, 
            $this->configuration['provider']['where'] ?? '', 
            $primaryKey,
            $this->output,
            $generator,
            $batchSize
        );
    }
}
```

And then use it in your YAML file. A processor factory needs to be set on the table level, and can be a simple class name, or a set of options which are available to your class.

```yaml
customer:
  customer_entity:
    processor_factory: \Custom\WoopTableFactory
    some_custom_config:
      option1: "test"
      option2: false
    columns:
      firstname:
        formatter:
          name: firstName
```

### Installation

Download the phar file:

```
curl -L -o masquerade.phar https://github.com/elgentos/masquerade/releases/latest/download/masquerade.phar
```

### Usage

```
$ php masquerade.phar run --help

Description:
  List of tables (and columns) to be faked

Usage:
  run [options]

Options:
      --platform[=PLATFORM]
      --driver[=DRIVER]      Database driver [mysql]
      --database[=DATABASE]
      --username[=USERNAME]
      --password[=PASSWORD]
      --host[=HOST]          Database host [localhost]
      --port[=PORT]          Database port [3306]
      --prefix[=PREFIX]      Database prefix [empty]
      --locale[=LOCALE]      Locale for Faker data [en_US]
      --group[=GROUP]        Comma-separated groups to run masquerade on [all]
      --with-integrity       Run with foreign key checks enabled
      --batch-size=BATCH-SIZE  Batch size to use for anonymization [default: 500]
```

You can also set these variables in a `config.yaml` file in the same location as where you run masquerade from, for example:

```yaml
platform: magento2
database: dbnamehere
username: userhere
password: passhere
host: localhost
port: porthere
```

### Running it nightly

Check out the wiki on [How to run Masquerade nightly with Gitlab CI/CD](https://github.com/elgentos/masquerade/wiki/How-to-run-Masquerade-nightly-with-Gitlab-CI-CD)

#### Magento 2 out-of-the-box rule-set

```
$ php masquerade.phar groups --platform=magento2

+----------+------------+--------------------------+--------------------+---------------------+
| Platform | Group      | Table                    | Column             | Formatter           |
+----------+------------+--------------------------+--------------------+---------------------+
| magento2 | invoice    | sales_invoice            | customer_note      | sentence            |
| magento2 | invoice    | sales_invoice_comment    | comment            | sentence            |
| magento2 | invoice    | sales_invoice_grid       | customer_email     | email               |
| magento2 | invoice    | sales_invoice_grid       | customer_name      | name                |
| magento2 | invoice    | sales_invoice_grid       | billing_name       | name                |
| magento2 | invoice    | sales_invoice_grid       | shipping_address   | address             |
| magento2 | invoice    | sales_invoice_grid       | billing_address    | address             |
| magento2 | creditmemo | sales_creditmemo         | customer_note      | sentence            |
| magento2 | creditmemo | sales_creditmemo_comment | comment            | sentence            |
| magento2 | creditmemo | sales_creditmemo_grid    | customer_email     | email               |
| magento2 | creditmemo | sales_creditmemo_grid    | customer_name      | name                |
| magento2 | creditmemo | sales_creditmemo_grid    | billing_name       | name                |
| magento2 | creditmemo | sales_creditmemo_grid    | shipping_address   | address             |
| magento2 | creditmemo | sales_creditmemo_grid    | billing_address    | address             |
| magento2 | review     | review_detail            | nickname           | firstName           |
| magento2 | review     | review_detail            | title              | sentence            |
| magento2 | review     | review_detail            | detail             | paragraph           |
| magento2 | newsletter | newsletter_subscriber    | subscriber_email   | email               |
| magento2 | order      | sales_order              | customer_email     | email               |
| magento2 | order      | sales_order              | customer_firstname | firstName           |
| magento2 | order      | sales_order              | customer_lastname  | lastName            |
| magento2 | order      | sales_order              | customer_dob       | dateTimeThisCentury |
| magento2 | order      | sales_order              | customer_taxvat    | vat                 |
| magento2 | order      | sales_order              | remote_ip          | ipv4                |
| magento2 | order      | sales_order              | customer_note      | sentence            |
| magento2 | order      | sales_order_grid         | customer_email     | email               |
| magento2 | order      | sales_order_grid         | customer_name      | name                |
| magento2 | order      | sales_order_grid         | shipping_name      | name                |
| magento2 | order      | sales_order_grid         | billing_name       | name                |
| magento2 | order      | sales_order_grid         | shipping_address   | address             |
| magento2 | order      | sales_order_grid         | billing_address    | address             |
| magento2 | order      | sales_order_address      | email              | email               |
| magento2 | order      | sales_order_address      | firstname          | firstName           |
| magento2 | order      | sales_order_address      | lastname           | lastName            |
| magento2 | order      | sales_order_address      | company            | company             |
| magento2 | order      | sales_order_address      | street             | streetAddress       |
| magento2 | order      | sales_order_address      | city               | city                |
| magento2 | order      | sales_order_address      | postcode           | postcode            |
| magento2 | order      | sales_order_address      | telephone          | phoneNumber         |
| magento2 | order      | sales_order_address      | fax                | phoneNumber         |
| magento2 | order      | sales_order_address      | vat_id             | vat                 |
| magento2 | quote      | quote                    | customer_email     | email               |
| magento2 | quote      | quote                    | customer_firstname | firstName           |
| magento2 | quote      | quote                    | customer_lastname  | lastName            |
| magento2 | quote      | quote                    | customer_dob       | dateTimeThisCentury |
| magento2 | quote      | quote                    | customer_taxvat    | vat                 |
| magento2 | quote      | quote                    | remote_ip          | ipv4                |
| magento2 | quote      | quote_address            | email              | email               |
| magento2 | quote      | quote_address            | firstname          | firstName           |
| magento2 | quote      | quote_address            | lastname           | lastName            |
| magento2 | quote      | quote_address            | company            | company             |
| magento2 | quote      | quote_address            | street             | streetAddress       |
| magento2 | quote      | quote_address            | city               | city                |
| magento2 | quote      | quote_address            | postcode           | postcode            |
| magento2 | quote      | quote_address            | telephone          | phoneNumber         |
| magento2 | quote      | quote_address            | fax                | phoneNumber         |
| magento2 | quote      | quote_address            | vat_id             | vat                 |
| magento2 | admin      | admin_user               | firstname          | firstName           |
| magento2 | admin      | admin_user               | lastname           | lastName            |
| magento2 | admin      | admin_user               | email              | email               |
| magento2 | admin      | admin_user               | username           | firstName           |
| magento2 | admin      | admin_user               | password           | password            |
| magento2 | email      | email_contact            | email              | email               |
| magento2 | email      | email_automation         | email              | email               |
| magento2 | email      | email_campaign           | email              | email               |
| magento2 | customer   | customer_entity          | email              | email               |
| magento2 | customer   | customer_entity          | firstname          | firstName           |
| magento2 | customer   | customer_entity          | lastname           | lastName            |
| magento2 | customer   | customer_address_entity  | firstname          | firstName           |
| magento2 | customer   | customer_address_entity  | lastname           | lastName            |
| magento2 | customer   | customer_address_entity  | company            | company             |
| magento2 | customer   | customer_address_entity  | street             | streetAddress       |
| magento2 | customer   | customer_address_entity  | city               | city                |
| magento2 | customer   | customer_address_entity  | postcode           | postcode            |
| magento2 | customer   | customer_address_entity  | telephone          | phoneNumber         |
| magento2 | customer   | customer_address_entity  | fax                | phoneNumber         |
| magento2 | customer   | customer_grid_flat       | name               | name                |
| magento2 | customer   | customer_grid_flat       | firstname          | firstName           |
| magento2 | customer   | customer_grid_flat       | email              | email               |
| magento2 | customer   | customer_grid_flat       | dob                | dateTimeThisCentury |
| magento2 | customer   | customer_grid_flat       | billing_full       | address             |
| magento2 | customer   | customer_grid_flat       | shipping_full      | address             |
| magento2 | customer   | customer_grid_flat       | billing_firstname  | firstName           |
| magento2 | customer   | customer_grid_flat       | billing_lastname   | lastName            |
| magento2 | customer   | customer_grid_flat       | billing_telephone  | phoneNumber         |
| magento2 | customer   | customer_grid_flat       | billing_postcode   | postcode            |
| magento2 | customer   | customer_grid_flat       | billing_street     | streetAddress       |
| magento2 | customer   | customer_grid_flat       | billing_city       | city                |
| magento2 | customer   | customer_grid_flat       | billing_fax        | phoneNumber         |
| magento2 | customer   | customer_grid_flat       | billing_vat_id     | vat                 |
| magento2 | customer   | customer_grid_flat       | billing_company    | company             |
| magento2 | shipment   | sales_shipment           | customer_note      | sentence            |
| magento2 | shipment   | sales_shipment_comment   | comment            | sentence            |
| magento2 | shipment   | sales_shipment_grid      | customer_email     | email               |
| magento2 | shipment   | sales_shipment_grid      | customer_name      | name                |
| magento2 | shipment   | sales_shipment_grid      | shipping_name      | name                |
| magento2 | shipment   | sales_shipment_grid      | billing_name       | name                |
| magento2 | shipment   | sales_shipment_grid      | shipping_address   | address             |
| magento2 | shipment   | sales_shipment_grid      | billing_address    | address             |
+----------+------------+--------------------------+--------------------+---------------------+

```

### Building from source

To build the phar from source you can use the `build.sh` script. Note that it depends on [Box](https://github.com/box-project/box) which is included in this repository.

```
# git clone https://github.com/elgentos/masquerade
# cd masquerade
# composer install
# chmod +x build.sh
# ./build.sh
# bin/masquerade
```

### Debian Packaging

To build a deb for this project run:

```
# apt-get install debhelper cowbuilder git-buildpackage
# export ARCH=amd64
# export DIST=buster
# cowbuilder --create --distribution buster --architecture amd64 --basepath /var/cache/pbuilder/base-$DIST-amd64.cow --mirror http://ftp.debian.org/debian/ --components=main
# echo "USENETWORK=yes" > ~/.pbuilderrc
# git clone https://github.com/elgentos/masquerade
# cd masquerade
# gbp buildpackage --git-pbuilder --git-dist=$DIST --git-arch=$ARCH --git-ignore-branch -us -uc -sa --git-ignore-new
```

To generate a new `debian/changelog` for a new release:
```
export BRANCH=master
export VERSION=$(date "+%Y%m%d.%H%M%S")
gbp dch --debian-tag="%(version)s" --new-version=$VERSION --debian-branch $BRANCH --release --commit
```

#### Credits

- Built by [elgentos](https://github.com/elgentos)
- Logo by [Caneco](https://twitter.com.com/caneco)
