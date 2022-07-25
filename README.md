# Magento 2 EAV Cleaner Console Command

Purpose of this project is to check for different flaws that can occur due to EAV and provide cleanup functions.

## Usage

Run `bin/magento` in the Magento 2 root and look for the `eav:` commands.

## Commands

* `eav:config:restore-use-default-value` Check if config admin value and storeview value are the same, so "use default" doesn't work anymore. Delete the storeview values.
* `eav:attributes:restore-use-default-value` Check if product attribute admin value and storeview value are the same, so "use default" doesn't work anymore. Delete the storeview values.
* `eav:attributes:remove-unused` Remove attributes with no values set in products and attributes that are not present in any attribute sets.
* `eav:media:remove-unused` Remove unused product images.
* `eav:clean:attributes-and-values-without-parent` Remove orphaned attribute values - those which are missing a parent entry (with the corresponding `backend_type`) in `eav_attribute`.

## Dry run
Use `--dry-run` to check result without modifying data.

## Force
Use `--force` to skip the confirmation prompt before modifying data.

## Installation
Installation with composer:

```bash
composer require magento-hackathon/module-eavcleaner-m2
```

### Contributors
- Nikita Zhavoronkova
- Anastasiia Sukhorukova
- Peter Jaap Blaakmeer

### Special thanks to
- Benno Lippert
- Damian Luszczymak
- Joke Puts
- Ralf Siepker
