# Stricker → WooCommerce Data Mapping

Status: specification / design document. No production import behavior is changed by this document.

## Scope

This document defines the data relationship that the import engine should use when converting the Stricker CSV catalog into WooCommerce data.

The mapping deliberately does **not** include Yoast-specific fields. SEO fields supplied by Stricker remain source data until a future SEO implementation is designed separately.

A critical rule is that numeric prefixes in Stricker product names must not automatically be interpreted as part of an SEO title. Fields such as `SEOName` must be treated according to their actual source semantics, not concatenated blindly with `Name`.

## Source datasets and roles

| Dataset | Primary role | Expected relationship |
|---|---|---|
| `products` | Product master data | One logical product identified by `ProdReference` |
| `optionals` | Product option/SKU data | One or more records related to `ProdReference` |
| `optionalscomplete` | Expanded SKU/variant data, pricing and customization data | One or more records related to `ProdReference`, normally identified by `Sku` / `WebSku` |
| `stocks` | Inventory | Match to SKU where possible; must not be matched only by display name |
| `producttypes` | Category taxonomy | `TypeCode` / `SubTypeCode` resolve category and subcategory names |
| `colors` | Color reference data | Used to normalize color codes/descriptions when required |
| `optionalsPrice` | Additional pricing source | Reserved for pricing reconciliation when downloaded |
| `customizationOptions` | Customization definitions | Reserved for future personalization mapping |
| `customizationTables` | Customization pricing/table definitions | Reserved for future personalization mapping |

## Canonical identifiers

The import engine should use identifiers in this order:

1. Product master: `ProdReference`.
2. Variant/SKU: `WebSku`, falling back to `Sku` when `WebSku` is empty.
3. Inventory: SKU key, matched against the canonical variant SKU.
4. Taxonomy: `TypeCode` + `SubTypeCode`.

The engine must not use product name as the primary relationship key.

## Product master mapping

Fields observed in the supplied Stricker product data are classified below.

| Stricker field | WooCommerce target | Level | Rule / status |
|---|---|---|---|
| `ProdReference` | `_stricker_prod_reference` | Product | Canonical external product ID. Preserve exactly as source string. |
| `Name` | Product name | Product | Direct value unless future sanitization is required. |
| `Description` | Description | Product | Direct value; preserve source content. |
| `ShortDescription` | Short description | Product | Direct value. |
| `SEOName` | Stricker source metadata | Product | **Do not automatically use as WooCommerce title.** Preserve for future SEO decision. |
| `SEOShortDescription` | Stricker source metadata | Product | Preserve. Future SEO layer may consume it. |
| `SEOShortDescriptionCap` | Stricker source metadata | Product | Preserve; no automatic WooCommerce destination. |
| `IsTextil` | `_stricker_is_textil` | Product | Preserve boolean source value. |
| `HasColors` | `_stricker_has_colors` | Product | Preserve boolean source value; also informs variant analysis. |
| `HasSizes` | `_stricker_has_sizes` | Product | Preserve boolean source value; informs variant analysis. |
| `HasCapacitys` | `_stricker_has_capacitys` | Product | Preserve boolean source value; informs variant analysis. |
| `CombinedSizes` | `_stricker_combined_sizes` | Product | Preserve; do not treat as a variation attribute unless actual size records exist. |
| `Gender` | Product attribute/meta | Product | Preserve; final public WooCommerce destination to be decided. |
| `AvailableGross` | `_stricker_available_gross` | Product | Preserve source flag. |
| `BoxLengthMM` | `_stricker_box_length_mm` | Product | Logistics metadata; do not use as product dimensions automatically. |
| `BoxWidthMM` | `_stricker_box_width_mm` | Product | Logistics metadata. |
| `BoxHeightMM` | `_stricker_box_height_mm` | Product | Logistics metadata. |
| `BoxSizeM` | `_stricker_box_size_m` | Product | Logistics metadata. |
| `BoxWeightKG` | `_stricker_box_weight_kg` | Product | Logistics metadata; distinct from sellable unit weight. |
| `BoxVolume` | `_stricker_box_volume` | Product | Logistics metadata. |
| `BoxQuantity` | `_stricker_box_quantity` | Product | Packaging metadata. |
| `BoxInnerQuantity` | `_stricker_box_inner_quantity` | Product | Packaging metadata. |
| `Multiplier` | `_stricker_multiplier` | Product | Preserve source value; pricing/quantity semantics require confirmation. |
| `Taric` | `_stricker_taric` | Product | Preserve as source/taxonomy metadata. Do not assume a Brazilian fiscal destination. |
| `Type` | Product category name | Product | Resolve through `TypeCode` where possible. |
| `TypeCode` | Product category external ID | Product | Canonical taxonomy key. |
| `SubType` | Product subcategory name | Product | Resolve through `SubTypeCode` where possible. |
| `SubTypeCode` | Product subcategory external ID | Product | Canonical taxonomy key. |
| `MainImage` | Featured image | Product | Future implementation: resolve/download source image. |
| `BoxImage` | Product media/meta | Product | Packaging image; not featured image by default. |
| `BagImage` | Product media/meta | Product | Packaging image; not featured image by default. |
| `PouchImage` | Product media/meta | Product | Packaging image; not featured image by default. |
| `AditionalImageList` | Product gallery | Product | Parse according to source delimiter/format. |
| `AllImageList` | Product gallery | Product | Parse list and deduplicate against main image. |
| `Brand` | Product attribute/meta | Product | Preserve as a structured brand value. Exact public taxonomy destination is a later design decision. |
| `CountryOfOrigin` | `_stricker_country_of_origin` | Product | Preserve source value. |
| `PvcFree` | `_stricker_pvc_free` | Product | Preserve boolean. |
| `Properties` | Product custom field | Product | Preserve source text; no automatic interpretation. |
| `ProductCare` | Product custom field | Product | Preserve. |
| `WeightGr` | Product weight candidate | Product | Use only when populated and semantics are confirmed. |
| `Certificates` | Product custom field | Product | Preserve structured/text source value. |
| `Composition` | Product custom field / attribute | Product | Preserve; final public destination later. |
| `Packing` | Product custom field | Product | Preserve. |
| `Repacking` | Product custom field | Product | Preserve. |
| `RefillType` | Product custom field / attribute | Product | Preserve. |
| `BatteryType` | Product custom field / attribute | Product | Preserve. |
| `Materials` | Product attribute/meta | Product | Preserve as structured material value(s). |
| `PaperSize` | Product attribute/meta | Product | Preserve when populated. |
| `PaperGramage` | Product attribute/meta | Product | Preserve when populated. |
| `CapacityMah` | Product attribute/meta | Product | Preserve when populated. |
| `CapacityGB` | Product attribute/meta | Product | Preserve when populated. |
| `VideoLink` | Product media/meta | Product | Preserve URL; no automatic embed behavior yet. |
| `InkColor` | Product attribute/meta | Product | Preserve when populated. |
| `VideoLinkVimeo` | Product media/meta | Product | Preserve URL. |
| `OtherDetails` | Product custom field | Product | Preserve. |
| `Sizes` | Product attribute source | Product | Parse only when populated and semantically valid. |
| `Capacitys` | Product attribute source | Product | Parse only when populated and semantically valid. |
| `KeyWords` | Stricker source metadata | Product | Preserve original string/list. Do not automatically assign as WooCommerce tags or SEO focus keyphrase. |
| `Colors` | Product attribute source | Product | Parse into normalized color values. |
| `ProductComponents` | Customization source metadata | Product | Preserve; future customization implementation. |
| `RelatedReferences` | Related-product source metadata | Product | Preserve references; future relationship implementation. |
| `ProductDefaultComponent` | Customization source metadata | Product | Preserve. |
| `Video360` | Product media/meta | Product | Preserve source value. |
| `ProductComponentLocations` | Customization source metadata | Product | Preserve. |
| `ProductComponentDefaultLocation` | Customization source metadata | Product | Preserve. |
| `ProductComponentDefaultLocationAreaMM` | Customization source metadata | Product | Preserve. |
| `ProductComposedLocations` | Customization source metadata | Product | Preserve. |
| `CustomizationTypes` | Customization source metadata | Product | Preserve. |
| `CustomizationDefaultType` | Customization source metadata | Product | Preserve. |
| `CustomizationDefaultTable` | Customization source metadata | Product | Preserve. |
| `CustomizationTables` | Customization source metadata | Product | Preserve. |
| `CustomizationTableOptions` | Customization source metadata | Product | Preserve. |
| `CustomizationDefault` | Customization source metadata | Product | Preserve. |
| `CustomizationDefaultTableMaxColors` | Customization source metadata | Product | Preserve numeric source value. |
| `CustomizationDefaultHandlingCosts` | Customization source metadata | Product | Preserve numeric source value. |
| `CustomizationDefaultPrintingLines` | Customization source metadata | Product | Preserve. |
| `IsSeasonal` | `_stricker_is_seasonal` | Product | Preserve boolean. |
| `SeasonalOccasion` | Product custom field | Product | Preserve. |
| `SeasonalStartDate` | Product custom field | Product | Preserve source date; timezone/format conversion later. |
| `SeasonalEndDate` | Product custom field | Product | Preserve source date; timezone/format conversion later. |
| `IsStockOut` | Inventory/source status | Product | Preserve source flag; inventory status must be determined from SKU stock data when available. |
| `OnlineExclusive` | `_stricker_online_exclusive` | Product | Preserve boolean. |
| `CertificateFiles` | Product media/meta | Product | Preserve references; future file import may be implemented. |
| `Weight` | Product weight candidate | Product | Requires distinction between unit weight and logistics weight. Do not blindly map to WooCommerce weight. |
| `Catalogs` | `_stricker_catalogs` | Product | Preserve source classification. |
| `UpdateDate` | `_stricker_update_date` | Product | Preserve source update timestamp for synchronization decisions. |

## Variant / Optional mapping

`optionalscomplete` is the richest source for sellable SKU-level records. A product with one SKU may still be represented as a simple WooCommerce product; multiple meaningful SKU records should generally become variations when the option data defines actual selectable differences.

| Stricker field | WooCommerce target | Level | Rule / status |
|---|---|---|---|
| `Sku` | SKU | Variation/product | Canonical SKU fallback. Preserve exact source value. |
| `WebSku` | SKU | Variation/product | Preferred canonical SKU when populated. |
| `ProdReference` | `_stricker_prod_reference` | Product/variation | Parent relationship key. |
| `Name` | Product name / variation source | Product | Normally inherited from parent. |
| `Description` | Description | Product | Normally inherited from parent. |
| `ShortDescription` | Short description | Product | Normally inherited from parent. |
| `IsTextil` | source metadata | Product/variation | Preserve. |
| `HasColors` | variant analysis | Product | Used with actual option records. |
| `HasSizes` | variant analysis | Product | Used with actual option records. |
| `HasCapacitys` | variant analysis | Product | Used with actual option records. |
| `Size` | Attribute: Size | Variation | Create only when non-empty and valid. |
| `Capacity` | Attribute: Capacity | Variation | Create only when non-empty and valid. |
| `ColorDesc1` | Attribute: Color | Variation | Primary color value. |
| `ColorHex1` | Color metadata | Variation | Preserve for future swatch/attribute implementation. |
| `ColorDesc2` | Attribute: Color secondary value | Variation | Use only if source semantics require multiple colors. |
| `ColorHex2` | Color metadata | Variation | Preserve. |
| `ColorCode` | `_stricker_color_code` | Variation | Preserve exact source code. |
| `MainImage` | Variation image candidate | Variation | If a SKU-specific image exists, use it for the variation. |
| `BoxImage` | variation/product media metadata | Variation | Preserve; not default variation image. |
| `BagImage` | variation/product media metadata | Variation | Preserve. |
| `PouchImage` | variation/product media metadata | Variation | Preserve. |
| `AditionalImageList` | gallery metadata | Variation | Parse if needed. |
| `AllImageList` | gallery metadata | Variation | Parse and deduplicate. |
| `OptionalImage1` | Variation image candidate | Variation | Prefer when it is SKU-specific and valid. |
| `OptionalImage2` | Variation image candidate | Variation | Preserve/use when populated. |
| `CombinedSizes` | source metadata | Variation | Do not create a size attribute from this field alone. |
| `Gender` | attribute/meta | Variation/product | Preserve. |
| `AvailableGross` | source metadata | Variation | Preserve. |
| `YourPrice` | Regular price | Variation/product | Preferred source when valid. |
| `Price1` ... `Price10` | Tier pricing metadata | Variation | Preserve quantity tiers. Do not silently convert into WooCommerce sale price. |
| `MinQt1` ... `MinQt10` | Tier quantity metadata | Variation | Preserve corresponding minimum quantities. |
| `MaxColors` | Customization metadata | Variation | Preserve. |
| `MaxHandlingCost` | Customization metadata | Variation | Preserve. |
| `Component1` ... `Component8` | Customization metadata | Variation | Preserve populated component records. |
| `ComponentNImage` | Customization metadata/media | Variation | Preserve. |
| `LocationN` | Customization metadata | Variation | Preserve. |
| `ComposedLocationN` | Customization metadata | Variation | Preserve. |
| `LocationNImage` | Customization metadata/media | Variation | Preserve. |
| `AreaN` | Customization metadata | Variation | Preserve. |
| `AreaNImage` | Customization metadata/media | Variation | Preserve. |
| `TableCodesN` | Customization metadata | Variation | Preserve. |
| `TableCodesOptionsN` | Customization metadata | Variation | Preserve. |
| `MaxColorsN` | Customization metadata | Variation | Preserve. |
| `CustomizationTypesN` | Customization metadata | Variation | Preserve. |
| `HandlingCostsN` | Customization metadata | Variation | Preserve. |
| `SizeLengthCM` | Dimension/source metadata | Variation | Preserve; not automatically mapped to WooCommerce dimensions. |
| `SizeWidthCM` | Dimension/source metadata | Variation | Preserve. |
| `IsStockOut` | inventory/source status | Variation | Preserve source flag; inventory should use Stocks when available. |
| `OnlineExclusive` | source metadata | Variation | Preserve. |
| `NewProduct` | source metadata | Variation | Preserve. |
| `Weight` | weight candidate | Variation | Requires semantic confirmation before WooCommerce weight mapping. |
| `CertificateFiles` | media/meta | Variation | Preserve. |
| `Catalogs` | source metadata | Variation | Preserve. |
| `UpdateDate` | source update timestamp | Variation | Preserve. |
| `NoReplenishment` | inventory/source metadata | Variation | Preserve boolean. |
| `CustomizationDefaultShortTable` | customization metadata | Variation | Preserve. |
| `TableFullCode1` ... `TableFullCode8` | customization metadata | Variation | Preserve populated values. |

## Pricing rules

Pricing must be treated independently from product identity and inventory.

Preferred initial source for the sellable SKU price:

`YourPrice` → fallback to `Price1` when `YourPrice` is empty.

The tier fields `MinQtN` + `PriceN` must be retained as structured source data. They must not be interpreted as WooCommerce sale prices without an explicit business rule.

`optionalsPrice`, when available, should later be reconciled against `optionalscomplete` before the final pricing engine is considered complete.

## Inventory rules

Inventory relationship must be SKU-based.

Example:

`ProdReference = 11103`

`WebSku = 11103-103`

The stock record should be matched using `11103-103`, not by product name and not merely by `ProdReference`.

`IsStockOut` is useful as source information, but it should not override a valid stock quantity without a documented precedence rule.

## Category rules

`producttypes` contains a flattened taxonomy structure with fields such as:

- `TypeCode`
- `TypeDescription`
- `SubTypeCode1` ... `SubTypeCode34`
- `SubTypeDescription1` ... `SubTypeDescription34`

The importer should normalize this into a lookup structure:

`TypeCode → TypeDescription`

and

`TypeCode + SubTypeCode → SubTypeDescription`

Products should be assigned using their source codes, not by matching category names.

The intended WooCommerce hierarchy is:

`TypeDescription`

└── `SubTypeDescription`

The source codes should remain available as metadata so that synchronization does not depend on translated/display names.

## Variation classification

The current diagnostic label `Variável` is not by itself sufficient to create a WooCommerce variable product.

The importer should consider a product variable when there are multiple distinct sellable SKU records for the same `ProdReference` and those records differ through one or more meaningful selectable attributes such as color, size or capacity.

A product with only one SKU should normally be imported as a simple product unless another confirmed WooCommerce requirement makes a variable product necessary.

`HasColors`, `HasSizes` and `HasCapacitys` are supporting signals, not the sole classification rule.

## Images

Image mapping must be SKU-aware.

For a product with multiple colors/SKUs, a single `MainImage` from the parent should not overwrite a SKU-specific image. Variation-level images should be selected from the optional/variant image fields when available.

Image filename values such as `11103_103.jpg` are source references, not guaranteed WordPress attachment URLs. A future image downloader/resolver must be implemented separately.

## SEO fields — intentionally excluded from the WooCommerce mapping

The following fields are retained as Stricker source data but are **not mapped to Yoast or another SEO plugin** by this specification:

- `SEOName`
- `SEOShortDescription`
- `SEOShortDescriptionCap`
- `KeyWords`

Important: numeric prefixes in `Name` and values such as `SEOName = 11103` must not be assumed to mean that the product SEO title should be `11103 + Name`. The meaning of these fields must remain source-specific until a dedicated SEO strategy is implemented.

## Fields requiring future datasets or validation

The following areas should remain open until the corresponding CSVs are downloaded and inspected:

1. `optionalsPrice` — final price reconciliation.
2. `customizationOptions` — customization option normalization.
3. `customizationTables` — customization cost/table normalization.
4. `colors` — canonical color-code lookup.
5. `stocks` — exact stock columns and SKU relationship.

## Synchronization principles

1. Never use product display name as the primary key.
2. Preserve original Stricker identifiers and source values in metadata where there is no direct WooCommerce equivalent.
3. Do not discard source fields merely because they have no immediate WooCommerce destination.
4. Separate parent product data from SKU/variation data.
5. Keep inventory, pricing and customization as independent domains.
6. Do not overwrite manually managed WooCommerce data until an explicit synchronization policy exists.
7. Keep SEO outside this importer specification for now.
8. Make mapping rules deterministic and based on source codes/identifiers wherever possible.
