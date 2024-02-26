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

## Additional options for `eav:attributes:restore-use-default-value`

### Always remove
Use `--always_restore` to remove all values, even if the scoped value is not equal to the base value.

### Store codes
Use `--store_codes=your_store_code` to only remove values for this store.

### Include attributes
Use `--include_attributes=some_attribute,some_other_attribute` to only delete values for these attributes.

### Exclude attributes
Use `--exclude_attributes=some_attribute,some_other_attribute` to preserve values for these attributes.

## Installation
Installation with composer:

```bash
composer require magento-hackathon/module-eavcleaner-m2
```

### Contributors
- Nikita Zhavoronkova
- Anastasiia Sukhorukova
- Peter Jaap Blaakmeer
- Rutger Rademaker

### Special thanks to
- Benno Lippert
- Damian Luszczymak
- Joke Puts
- Ralf Siepker
