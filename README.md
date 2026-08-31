# Stricker WooCommerce Catalog Sync CSV

Plugin base for downloading and processing the Stricker catalogue through the official HTTPS CSV endpoints.

## Version 0.1.0

Initial architecture:
- WordPress/WooCommerce admin screen
- Access Key storage
- HTTPS CSV downloads
- Local catalogue storage in uploads
- Support for Products, ProductTypes, Optionals, Prices, Colors, Stocks and related datasets
- Streaming HTTP download to avoid loading the whole file into PHP memory
- Initial CSV reader designed for paginated catalogue processing

Product import into WooCommerce is intentionally not enabled yet; the first phase validates the CSV data structure before mapping products and variations.
