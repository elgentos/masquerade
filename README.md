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
- Shopware 6

### Customization

You can add your own configuration files in a directory named `config` in the same directory as where you run masquerade. The configuration files will be merged with any already present configuration files for that platform, overriding any out-of-the-box values.

See the [Magento 2 YAML files](https://github.com/elgentos/masquerade/tree/master/src/config/magento2) as examples for notation.

For example, to override the `admin.yaml` for Magento 2, you place a file in `config/magento2/admin.yaml`. For example, if you want to completely disable/skip a group, just add this content;

```
admin:
```

You can add your own config files for custom tables or tables from 3rd party vendors. Here are a few examples:
- [Magento 2 Aheadworks YAML file](https://github.com/elgentos/masquerade/wiki/%5BMagento-2%5D-Aheadworks-YAML-file)
- [Magento 2 Amasty YAML file](https://github.com/elgentos/masquerade/wiki/%5BMagento-2%5D-Amasty-YAML-file)
- [Shopware 6 Frosh YAML file](https://github.com/elgentos/masquerade/wiki/%5BShopware-6%5D-Frosh-YAML-file)

To generate such files, you can run the `masquerade identify` command. This will look for columns that show a hint of personal identifiable data in the name, such as `name` or `address`. It will interactively ask you to add it to a config file for the chosen platform.

### Partial anonymization

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

Check out the wiki on how to run Masquerade nightly in CI/CD;
- [Usage with Gitlab CI/CD](https://github.com/elgentos/masquerade/wiki/Usage-with-Gitlab-CI-CD)
- [Usage with Github Actions](https://github.com/elgentos/masquerade/wiki/Usage-with-Github-Actions)
- [Usage with Bitbucket Pipelines](https://github.com/elgentos/masquerade/wiki/Usage-with-Bitbucket-Pipelines)

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
- Logo by [Caneco](https://twitter.com/caneco)
