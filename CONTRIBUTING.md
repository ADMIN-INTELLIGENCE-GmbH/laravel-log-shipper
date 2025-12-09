# Contributing to Laravel Log Shipper

Thank you for your interest in contributing to Laravel Log Shipper! This document provides guidelines and information for contributors.

## Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper.git
   cd laravel-log-shipper
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run tests:
   ```bash
   composer test
   ```

## Code Style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for code styling. Before submitting a pull request, please run:

```bash
composer lint
```

To check if your code passes the style guidelines without making changes:

```bash
composer lint-check
```

## Pull Request Process

1. Fork the repository and create your branch from `main`.
2. If you've added code that should be tested, add tests.
3. Ensure the test suite passes (`composer test`).
4. Make sure your code follows the code style (`composer lint`).
5. Update documentation if needed.
6. Create your pull request.

## Reporting Bugs

When reporting bugs, please include:

- Your PHP version
- Your Laravel version
- Steps to reproduce the issue
- Expected behavior
- Actual behavior

## Feature Requests

We welcome feature requests! Please open an issue describing:

- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered

## Questions

If you have questions, feel free to open an issue with the "question" label.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
