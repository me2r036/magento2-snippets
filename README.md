# magento2-snippets
Magento2 code snippets

### Sort options alphabetically
* The official Magento displays select options by ID other than option labels
* May not desired for large number of options - difficult & time consuming to find an option if the option list is long
* This plugin overrides the default magento behaviour and simply sorts the select options by labels alphabetically

### Image importing
* Importing product images takes relatively long time - average 60s for a product
* Not acceptable for large amount of products, e.g. 5000 products would need 83 hours to be updated
* This snippet overrides the magento core functions and utilises resource model to deal with product images effectively
* 10 times faser - average 6s for a product updating, 5000 products now take 8 hours to be updated
