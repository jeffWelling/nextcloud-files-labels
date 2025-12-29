# Contributing to File Labels

Thank you for your interest in contributing to File Labels for Nextcloud!

## Getting Started

### Prerequisites

- Node.js 20+
- PHP 8.1+
- Podman and podman-compose (for running tests)
- A Nextcloud 28+ development environment

### Development Setup

1. Clone the repository into your Nextcloud's `custom_apps` directory:
   ```bash
   cd /path/to/nextcloud/custom_apps
   git clone https://github.com/jeffWelling/nextcloud-files-labels.git files_labels
   cd files_labels
   ```

2. Install dependencies:
   ```bash
   make install
   ```

3. Build the frontend:
   ```bash
   make build
   ```

4. Enable the app:
   ```bash
   php occ app:enable files_labels
   ```

### Development Workflow

For frontend development with hot reload:
```bash
make dev
```

## Running Tests

### Unit Tests
```bash
make test-unit
```

### E2E Tests (Single Database)
```bash
make test-e2e
```

### E2E Tests (All Databases)
```bash
make test-all-databases
```

Or test against a specific database:
```bash
make test-sqlite
make test-mysql
make test-pgsql
```

## Code Style

### PHP
- Follow PSR-12 coding standards
- Use strict types: `declare(strict_types=1);`
- Include SPDX license headers in all files

### JavaScript/Vue
- Follow the Nextcloud ESLint configuration
- Use Vue 2.7 single-file components

## Submitting Changes

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Make your changes
4. Run tests: `make test`
5. Commit with a clear message
6. Push to your fork
7. Open a Pull Request

### Commit Messages

- Use present tense ("Add feature" not "Added feature")
- Keep the first line under 72 characters
- Reference issues when applicable: "Fix #123: ..."

### Pull Request Guidelines

- Describe what your changes do
- Include screenshots for UI changes
- Ensure all tests pass
- Update documentation if needed

## Reporting Issues

- Use GitHub Issues
- Include Nextcloud and PHP version
- Include steps to reproduce
- Include relevant logs

## License

By contributing, you agree that your contributions will be licensed under the AGPL-3.0-or-later license.
