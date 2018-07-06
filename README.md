# VAT Exemption Module

This module adds handling of VAT exemptions of various tax laws.

## Description

This module handles Value Added Tax (VAT) exemptions of various kinds. For example, it removes the VAT from price display and invoices for EU intra-community sales in case the customer enters his VAT number. This number optionally gets verified with an online database automatically.

Another application are VAT exemptions under Group 12 of Schedule 8 of the Value Added Tax Act 1994 in the United Kingdom.

## License

This software is published under the [Academic Free License 3.0](https://opensource.org/licenses/afl-3.0.php)

## Contributing

thirty bees modules are Open Source extensions to the thirty bees e-commerce solution. Everyone is welcome and even encouraged to contribute with their own improvements.

For details, see [CONTRIBUTING.md](https://github.com/thirtybees/thirtybees/blob/1.0.x/CONTRIBUTING.md) in the thirty bees core repository.

## Packaging

To build a package for the thirty bees distribution machinery or suitable for importing it into a shop, run `tools/buildmodule.sh` of the thirty bees core repository from inside the module root directory.

For module development, one clones this repository into `modules/` of the shop, alongside the other modules. It should work fine without packaging.

## Roadmap

#### Short Term

* None currently.

#### Long Term

* None currently.

