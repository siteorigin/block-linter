# WordPress Block Linter

A standalone PHP linter for WordPress Gutenberg blocks that validates block structure, attributes, and common issues without requiring WordPress.

## Features

- **Standalone Operation**: No WordPress installation required
- **Comprehensive Validation**: Structure, attributes, nesting, and more
- **Configurable Rules**: Customize validation to your needs
- **CLI Interface**: Easy integration with CI/CD pipelines
- **Multiple Input Methods**: File input or stdin

## Installation

1. Clone or download this directory
2. Ensure PHP 7.0+ is installed
3. Make the script executable: `chmod +x block-linter.php`

## Usage

### Basic Usage

```bash
# Lint a file
php block-linter.php -f content.html

# Use stdin
echo "<!-- wp:paragraph -->Test<!-- /wp:paragraph -->" | php block-linter.php

# Verbose output
php block-linter.php -f content.html -v
```

### With Configuration

```bash
php block-linter.php -f content.html -c linter-config.json
```

### Options

- `-f, --file <path>`: File to lint
- `-c, --config <path>`: Configuration file (JSON)
- `-v, --verbose`: Verbose output
- `-h, --help`: Show help message

## Configuration

Create a JSON configuration file to customize validation rules:

```json
{
	"max_nesting_depth": 10,
	"max_block_count": 1000,
	"max_attribute_size": 10000,
	"allowed_blocks": ["core/paragraph", "core/heading"],
	"forbidden_blocks": ["core/html"],
	"check_empty_blocks": true,
	"validate_namespaces": true
}
```

### Configuration Options

- `max_nesting_depth`: Maximum allowed block nesting depth (default: 10)
- `max_block_count`: Maximum number of blocks allowed (default: 1000)
- `max_attribute_size`: Maximum size of block attributes in bytes (default: 10000)
- `allowed_blocks`: Array of allowed block types (empty = all allowed)
- `forbidden_blocks`: Array of forbidden block types
- `check_empty_blocks`: Warn about empty blocks (default: true)
- `validate_namespaces`: Validate block namespace format (default: true)

## Validation Rules

### Errors (Exit Code 1)

- **Parse Errors**: Malformed block syntax
- **Unclosed Blocks**: Missing closing tags
- **Invalid Block Names**: Incorrect namespace format
- **Invalid Attributes**: Wrong attribute values (e.g., heading level > 6)
- **Exceeded Limits**: Nesting depth, block count, or attribute size

### Warnings

- **Empty Blocks**: Blocks with no content
- **Unknown Core Blocks**: Unrecognized core/* blocks
- **Orphaned Closers**: Closing tags without opening tags
- **Missing Attributes**: Required attributes not present

## Examples

### Example: Valid Content

```html
<!-- wp:paragraph -->
<p>This is a paragraph.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>This is a heading</h2>
<!-- /wp:heading -->
```

Output: `✅ No issues found!`

### Example: Invalid Content

```html
<!-- wp:heading {"level":7} -->
<h2>Invalid heading level</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Unclosed paragraph
```

Output:
```
❌ ERRORS (2):
  - [invalid_attribute_value] Invalid heading level: 7
  - [unclosed_block] Unclosed block found: 'paragraph'
```

## Integration Examples

### Pre-commit Hook

```bash
#!/bin/bash
# .git/hooks/pre-commit

# Check all HTML files for block errors
for file in $(git diff --cached --name-only | grep '\.html$'); do
	if ! php block-linter/block-linter.php -f "$file"; then
		echo "Block validation failed for $file"
		exit 1
	fi
done
```

### CI/CD Pipeline

```yaml
# GitHub Actions example
- name: Lint WordPress Blocks
  run: |
	find . -name "*.html" -exec php block-linter/block-linter.php -f {} \;
```

### PHP Integration

```php
require_once 'block-linter/block-linter.php';

$linter = new BlockLinter([
	'max_nesting_depth' => 5,
	'forbidden_blocks' => ['core/html']
]);

$result = $linter->lint($content);
if (!$result) {
	$errors = $linter->getErrors();
	$warnings = $linter->getWarnings();
	// Handle errors...
}
```

## Exit Codes

- `0`: Success (no errors, may have warnings)
- `1`: Validation errors found

## License

This tool is provided as-is for WordPress block validation purposes.
