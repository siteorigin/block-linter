#!/usr/bin/env php
<?php
/**
 * Standalone WordPress Block Linter
 * 
 * A lightweight validator for WordPress Gutenberg blocks that doesn't require WordPress.
 * Based on WP_Block_Parser logic.
 */

// Minimal block parser implementation based on WP_Block_Parser
class BlockParser {
    public $document;
    public $offset;
    public $output;
    public $stack;

    public function parse($document) {
        $this->document = $document;
        $this->offset = 0;
        $this->output = array();
        $this->stack = array();

        while ($this->proceed()) {
            continue;
        }

        return $this->output;
    }

    private function proceed() {
        $next_token = $this->next_token();
        list($token_type, $block_name, $attrs, $start_offset, $token_length) = $next_token;
        $stack_depth = count($this->stack);

        $leading_html_start = $start_offset > $this->offset ? $this->offset : null;

        switch ($token_type) {
            case 'no-more-tokens':
                if (0 === $stack_depth) {
                    $this->add_freeform();
                    return false;
                }

                if (1 === $stack_depth) {
                    $this->add_block_from_stack();
                    return false;
                }

                while (0 < count($this->stack)) {
                    $this->add_block_from_stack();
                }
                return false;

            case 'void-block':
                if (0 === $stack_depth) {
                    if (isset($leading_html_start)) {
                        $this->output[] = $this->freeform(
                            substr(
                                $this->document,
                                $leading_html_start,
                                $start_offset - $leading_html_start
                            )
                        );
                    }

                    $this->output[] = $this->create_block($block_name, $attrs, array(), '', array());
                    $this->offset = $start_offset + $token_length;
                    return true;
                }

                $this->add_inner_block(
                    $this->create_block($block_name, $attrs, array(), '', array()),
                    $start_offset,
                    $token_length
                );
                $this->offset = $start_offset + $token_length;
                return true;

            case 'block-opener':
                array_push(
                    $this->stack,
                    (object) array(
                        'block' => $this->create_block($block_name, $attrs, array(), '', array()),
                        'token_start' => $start_offset,
                        'token_length' => $token_length,
                        'prev_offset' => $start_offset + $token_length,
                        'leading_html_start' => $leading_html_start
                    )
                );
                $this->offset = $start_offset + $token_length;
                return true;

            case 'block-closer':
                if (0 === $stack_depth) {
                    $this->add_freeform();
                    return false;
                }

                if (1 === $stack_depth) {
                    $this->add_block_from_stack($start_offset);
                    $this->offset = $start_offset + $token_length;
                    return true;
                }

                $stack_top = array_pop($this->stack);
                $html = substr($this->document, $stack_top->prev_offset, $start_offset - $stack_top->prev_offset);
                $stack_top->block['innerHTML'] .= $html;
                $stack_top->block['innerContent'][] = $html;
                $stack_top->prev_offset = $start_offset + $token_length;

                $this->add_inner_block(
                    $stack_top->block,
                    $stack_top->token_start,
                    $stack_top->token_length,
                    $start_offset + $token_length
                );
                $this->offset = $start_offset + $token_length;
                return true;

            default:
                $this->add_freeform();
                return false;
        }
    }

    private function next_token() {
        $matches = null;

        $has_match = preg_match(
            '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s',
            $this->document,
            $matches,
            PREG_OFFSET_CAPTURE,
            $this->offset
        );

        if (false === $has_match || 0 === $has_match) {
            return array('no-more-tokens', null, null, null, null);
        }

        list($match, $started_at) = $matches[0];

        $length = strlen($match);
        $is_closer = isset($matches['closer']) && -1 !== $matches['closer'][1];
        $is_void = isset($matches['void']) && -1 !== $matches['void'][1];
        $namespace = $matches['namespace'];
        $namespace = (isset($namespace) && -1 !== $namespace[1]) ? $namespace[0] : 'core/';
        $name = $namespace . $matches['name'][0];
        $has_attrs = isset($matches['attrs']) && -1 !== $matches['attrs'][1];

        $attrs = $has_attrs
            ? json_decode($matches['attrs'][0], true)
            : array();

        if ($is_void) {
            return array('void-block', $name, $attrs, $started_at, $length);
        }

        if ($is_closer) {
            return array('block-closer', $name, null, $started_at, $length);
        }

        return array('block-opener', $name, $attrs, $started_at, $length);
    }

    private function freeform($inner_html) {
        return array(
            'blockName' => null,
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => $inner_html,
            'innerContent' => array($inner_html)
        );
    }

    private function create_block($name, $attrs, $inner_blocks, $inner_html, $inner_content) {
        return array(
            'blockName' => $name,
            'attrs' => $attrs,
            'innerBlocks' => $inner_blocks,
            'innerHTML' => $inner_html,
            'innerContent' => $inner_content
        );
    }

    private function add_freeform($length = null) {
        $length = $length ? $length : strlen($this->document) - $this->offset;

        if (0 === $length) {
            return;
        }

        $this->output[] = $this->freeform(substr($this->document, $this->offset, $length));
    }

    private function add_inner_block($block, $token_start, $token_length, $last_offset = null) {
        $parent = $this->stack[count($this->stack) - 1];
        $parent->block['innerBlocks'][] = $block;
        $html = substr($this->document, $parent->prev_offset, $token_start - $parent->prev_offset);

        if (!empty($html)) {
            $parent->block['innerHTML'] .= $html;
            $parent->block['innerContent'][] = $html;
        }

        $parent->block['innerContent'][] = null;
        $parent->prev_offset = $last_offset ? $last_offset : $token_start + $token_length;
    }

    private function add_block_from_stack($end_offset = null) {
        $stack_top = array_pop($this->stack);
        $prev_offset = $stack_top->prev_offset;

        $html = isset($end_offset)
            ? substr($this->document, $prev_offset, $end_offset - $prev_offset)
            : substr($this->document, $prev_offset);

        if (!empty($html)) {
            $stack_top->block['innerHTML'] .= $html;
            $stack_top->block['innerContent'][] = $html;
        }

        if (isset($stack_top->leading_html_start)) {
            $this->output[] = $this->freeform(
                substr(
                    $this->document,
                    $stack_top->leading_html_start,
                    $stack_top->token_start - $stack_top->leading_html_start
                )
            );
        }

        $this->output[] = $stack_top->block;
    }
}

// Block Linter with validation rules
class BlockLinter {
    private $parser;
    private $errors = array();
    private $warnings = array();
    private $config = array();

    public function __construct($config = array()) {
        $this->parser = new BlockParser();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    private function getDefaultConfig() {
        return array(
            'max_nesting_depth' => 10,
            'max_block_count' => 1000,
            'max_attribute_size' => 10000,
            'allowed_blocks' => array(),
            'forbidden_blocks' => array(),
            'require_closing_tags' => true,
            'validate_json' => true,
            'check_empty_blocks' => true,
            'validate_namespaces' => true,
            'core_blocks' => array(
                'core/paragraph', 'core/heading', 'core/list', 'core/quote',
                'core/image', 'core/gallery', 'core/video', 'core/audio',
                'core/columns', 'core/column', 'core/group', 'core/button',
                'core/buttons', 'core/separator', 'core/spacer', 'core/code',
                'core/preformatted', 'core/pullquote', 'core/table', 'core/verse',
                'core/embed', 'core/file', 'core/media-text', 'core/more',
                'core/nextpage', 'core/block', 'core/html', 'core/shortcode',
                'core/archives', 'core/categories', 'core/latest-comments',
                'core/latest-posts', 'core/calendar', 'core/rss', 'core/search',
                'core/tag-cloud', 'core/navigation', 'core/navigation-link',
                'core/site-logo', 'core/site-title', 'core/site-tagline',
                'core/query', 'core/post-template', 'core/post-title',
                'core/post-content', 'core/post-excerpt', 'core/post-featured-image',
                'core/post-date', 'core/post-author', 'core/post-terms',
                'core/loginout', 'core/social-links', 'core/social-link'
            )
        );
    }

    public function lintFile($filepath) {
        if (!file_exists($filepath)) {
            $this->errors[] = array(
                'type' => 'file_not_found',
                'message' => "File not found: $filepath"
            );
            return false;
        }

        $content = file_get_contents($filepath);
        return $this->lint($content, $filepath);
    }

    public function lint($content, $source = 'input') {
        $this->errors = array();
        $this->warnings = array();

        // Parse blocks
        try {
            $blocks = $this->parser->parse($content);
        } catch (Exception $e) {
            $this->errors[] = array(
                'type' => 'parse_error',
                'message' => "Failed to parse blocks: " . $e->getMessage(),
                'source' => $source
            );
            return false;
        }

        // Run validation rules
        $this->validateBlockCount($blocks);
        $this->validateBlocks($blocks, 0);
        $this->checkForUnclosedBlocks($content);
        $this->checkForOrphanedClosers($content);

        return empty($this->errors);
    }

    private function validateBlocks($blocks, $depth) {
        foreach ($blocks as $block) {
            if (!empty($block['blockName'])) {
                $this->validateBlock($block, $depth);
            }

            if (!empty($block['innerBlocks'])) {
                $this->validateBlocks($block['innerBlocks'], $depth + 1);
            }
        }
    }

    private function validateBlock($block, $depth) {
        // Check nesting depth
        if ($depth > $this->config['max_nesting_depth']) {
            $this->errors[] = array(
                'type' => 'max_depth_exceeded',
                'message' => "Block '{$block['blockName']}' exceeds maximum nesting depth of {$this->config['max_nesting_depth']}",
                'block' => $block['blockName'],
                'depth' => $depth
            );
        }

        // Validate block name
        $this->validateBlockName($block['blockName']);

        // Validate attributes
        if (!empty($block['attrs'])) {
            $this->validateAttributes($block);
        }

        // Check for empty blocks
        if ($this->config['check_empty_blocks']) {
            $this->checkEmptyBlock($block);
        }

        // Validate specific block types
        $this->validateSpecificBlockType($block);
    }

    private function validateBlockName($blockName) {
        // Check allowed/forbidden blocks
        if (!empty($this->config['allowed_blocks']) && !in_array($blockName, $this->config['allowed_blocks'])) {
            $this->errors[] = array(
                'type' => 'forbidden_block',
                'message' => "Block '$blockName' is not in the allowed blocks list",
                'block' => $blockName
            );
        }

        if (in_array($blockName, $this->config['forbidden_blocks'])) {
            $this->errors[] = array(
                'type' => 'forbidden_block',
                'message' => "Block '$blockName' is forbidden",
                'block' => $blockName
            );
        }

        // Validate namespace format
        if ($this->config['validate_namespaces']) {
            if (!preg_match('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $blockName)) {
                $this->errors[] = array(
                    'type' => 'invalid_block_name',
                    'message' => "Invalid block name format: '$blockName'",
                    'block' => $blockName
                );
            }
        }

        // Warn about unknown blocks
        if (strpos($blockName, 'core/') === 0 && !in_array($blockName, $this->config['core_blocks'])) {
            $this->warnings[] = array(
                'type' => 'unknown_core_block',
                'message' => "Unknown core block: '$blockName'",
                'block' => $blockName
            );
        }
    }

    private function validateAttributes($block) {
        $attrs_json = json_encode($block['attrs']);
        $attrs_size = strlen($attrs_json);

        // Check attribute size
        if ($attrs_size > $this->config['max_attribute_size']) {
            $this->errors[] = array(
                'type' => 'attribute_size_exceeded',
                'message' => "Attributes for block '{$block['blockName']}' exceed maximum size",
                'block' => $block['blockName'],
                'size' => $attrs_size,
                'max_size' => $this->config['max_attribute_size']
            );
        }

        // Validate specific attributes based on block type
        $this->validateBlockSpecificAttributes($block);
    }

    private function validateBlockSpecificAttributes($block) {
        switch ($block['blockName']) {
            case 'core/image':
                if (empty($block['attrs']['url']) && empty($block['attrs']['id'])) {
                    $this->warnings[] = array(
                        'type' => 'missing_required_attribute',
                        'message' => "Image block missing 'url' or 'id' attribute",
                        'block' => $block['blockName']
                    );
                }
                break;

            case 'core/heading':
                if (isset($block['attrs']['level'])) {
                    $level = $block['attrs']['level'];
                    if (!is_numeric($level) || $level < 1 || $level > 6) {
                        $this->errors[] = array(
                            'type' => 'invalid_attribute_value',
                            'message' => "Invalid heading level: $level",
                            'block' => $block['blockName']
                        );
                    }
                }
                break;

            case 'core/columns':
                if (isset($block['attrs']['columns'])) {
                    $columns = $block['attrs']['columns'];
                    if (!is_numeric($columns) || $columns < 1 || $columns > 6) {
                        $this->warnings[] = array(
                            'type' => 'invalid_attribute_value',
                            'message' => "Unusual column count: $columns",
                            'block' => $block['blockName']
                        );
                    }
                }
                break;
        }
    }

    private function checkEmptyBlock($block) {
        $has_content = !empty($block['innerHTML']) || !empty($block['innerBlocks']);
        
        if (!$has_content && !in_array($block['blockName'], array('core/spacer', 'core/separator'))) {
            $this->warnings[] = array(
                'type' => 'empty_block',
                'message' => "Empty block found: '{$block['blockName']}'",
                'block' => $block['blockName']
            );
        }
    }

    private function validateSpecificBlockType($block) {
        switch ($block['blockName']) {
            case 'core/column':
                // Column blocks should only exist inside columns
                // This is a simplified check - would need context for proper validation
                break;

            case 'core/navigation-link':
                // Navigation links should only exist inside navigation blocks
                break;
        }
    }

    private function validateBlockCount($blocks) {
        $count = $this->countBlocks($blocks);
        if ($count > $this->config['max_block_count']) {
            $this->errors[] = array(
                'type' => 'max_blocks_exceeded',
                'message' => "Total block count ($count) exceeds maximum ({$this->config['max_block_count']})",
                'count' => $count
            );
        }
    }

    private function countBlocks($blocks) {
        $count = 0;
        foreach ($blocks as $block) {
            if (!empty($block['blockName'])) {
                $count++;
            }
            if (!empty($block['innerBlocks'])) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }
        return $count;
    }

    private function checkForUnclosedBlocks($content) {
        preg_match_all('/<!-- wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?:{[^}]*}\s+)?-->/', $content, $openers);
        preg_match_all('/<!-- \/wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*-->/', $content, $closers);

        $opened = array();
        foreach ($openers[1] as $opener) {
            if (!isset($opened[$opener])) {
                $opened[$opener] = 0;
            }
            $opened[$opener]++;
        }

        $closed = array();
        foreach ($closers[1] as $closer) {
            if (!isset($closed[$closer])) {
                $closed[$closer] = 0;
            }
            $closed[$closer]++;
        }

        foreach ($opened as $block => $count) {
            $closed_count = isset($closed[$block]) ? $closed[$block] : 0;
            if ($count > $closed_count) {
                $this->errors[] = array(
                    'type' => 'unclosed_block',
                    'message' => "Unclosed block found: '$block' (opened $count times, closed $closed_count times)",
                    'block' => $block
                );
            }
        }
    }

    private function checkForOrphanedClosers($content) {
        // Simple check for closers without openers
        preg_match_all('/<!-- \/wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*-->/', $content, $matches, PREG_OFFSET_CAPTURE);
        
        foreach ($matches[1] as $match) {
            $block_name = $match[0];
            $position = $match[1];
            
            // Check if there's a corresponding opener before this position
            $before_content = substr($content, 0, $position);
            $opener_pattern = '/<!-- wp:' . preg_quote($block_name, '/') . '\s+(?:{[^}]*}\s+)?-->/';
            
            if (!preg_match($opener_pattern, $before_content)) {
                $this->warnings[] = array(
                    'type' => 'orphaned_closer',
                    'message' => "Closing tag without opening tag: '$block_name'",
                    'block' => $block_name
                );
            }
        }
    }

    public function getErrors() {
        return $this->errors;
    }

    public function getWarnings() {
        return $this->warnings;
    }

    public function formatResults($verbose = false) {
        $output = array();

        if (!empty($this->errors)) {
            $output[] = "\n❌ ERRORS (" . count($this->errors) . "):\n";
            foreach ($this->errors as $error) {
                $output[] = "  - [{$error['type']}] {$error['message']}";
                if ($verbose && isset($error['block'])) {
                    $output[] = "    Block: {$error['block']}";
                }
            }
        }

        if (!empty($this->warnings)) {
            $output[] = "\n⚠️  WARNINGS (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $warning) {
                $output[] = "  - [{$warning['type']}] {$warning['message']}";
                if ($verbose && isset($warning['block'])) {
                    $output[] = "    Block: {$warning['block']}";
                }
            }
        }

        if (empty($this->errors) && empty($this->warnings)) {
            $output[] = "\n✅ No issues found!";
        }

        return implode("\n", $output);
    }
}

// CLI Interface
if (php_sapi_name() === 'cli') {
    $options = getopt('f:c:vh', array('file:', 'config:', 'verbose', 'help'));

    if (isset($options['h']) || isset($options['help'])) {
        echo <<<HELP
WordPress Block Linter

Usage: php block-linter.php [options]

Options:
  -f, --file <path>     File to lint
  -c, --config <path>   Configuration file (JSON)
  -v, --verbose         Verbose output
  -h, --help           Show this help message

Configuration file example:
{
    "max_nesting_depth": 10,
    "max_block_count": 1000,
    "allowed_blocks": ["core/paragraph", "core/heading"],
    "forbidden_blocks": ["core/html"],
    "check_empty_blocks": true
}

HELP;
        exit(0);
    }

    $config = array();
    if (isset($options['c']) || isset($options['config'])) {
        $config_file = $options['c'] ?? $options['config'];
        if (file_exists($config_file)) {
            $config = json_decode(file_get_contents($config_file), true);
        }
    }

    $linter = new BlockLinter($config);
    $verbose = isset($options['v']) || isset($options['verbose']);

    if (isset($options['f']) || isset($options['file'])) {
        $file = $options['f'] ?? $options['file'];
        $result = $linter->lintFile($file);
        
        echo "Linting: $file\n";
        echo $linter->formatResults($verbose);
        echo "\n\n";
        
        exit($result ? 0 : 1);
    } else {
        // Read from stdin
        $content = file_get_contents('php://stdin');
        $result = $linter->lint($content, 'stdin');
        
        echo $linter->formatResults($verbose);
        echo "\n\n";
        
        exit($result ? 0 : 1);
    }
}