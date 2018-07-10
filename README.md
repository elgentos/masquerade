# Masquerade

## Faker-driven, platform-agnostic, locale-compatible data faker tool

Point Masquerade to a database, give it a rule-set defined in YAML and Masquerade will anonymize the data for you 
 automatically!
 
### Out-of-the-box supported frameworks

- Magento 2

### Customization

You can add your own configuration files in a directory named `config` in the same directory as where you run masquerade. The configuration files will be merged with any already present configuration files for that platform, overriding any out-of-the-box values.

See the Magento 2 YAML files as examples for notation. 

For formatters, you can use all default [Faker formatters](https://github.com/fzaninotto/Faker#formatters). 
 
### Installation

Download the phar file;

```
wget 
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
      --prefix[=PREFIX]      Database prefix [empty]
      --locale[=LOCALE]      Locale for Faker data [en_US]
      --group[=GROUP]        Which groups to run masquerade on [all]
```

You can also set these variables in a `config.yaml` file in the same locartion as where you run masquerade from, for example:

```yaml
platform: magento2
database: dbnamehere
username: userhere
password: passhere
host: localhost
``` 

#### Magento 2 out-of-the-box rule-set

```
$ php masquerade.phar list --platform=magento2

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

#### Built by elgentos