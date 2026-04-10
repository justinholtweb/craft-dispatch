<?php

namespace justinholtweb\dispatch\helpers;

class CssInliner
{
    /**
     * Inline CSS styles from <style> blocks into element style attributes.
     * Basic implementation for email compatibility.
     */
    public static function inline(string $html): string
    {
        if (empty($html)) {
            return $html;
        }

        // Extract <style> blocks
        $styles = [];
        if (preg_match_all('/<style[^>]*>(.*?)<\/style>/si', $html, $matches)) {
            foreach ($matches[1] as $styleBlock) {
                $styles[] = $styleBlock;
            }
        }

        if (empty($styles)) {
            return $html;
        }

        // Parse CSS rules
        $rules = [];
        foreach ($styles as $css) {
            // Remove comments
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);

            // Parse rules
            preg_match_all('/([^{]+)\{([^}]+)\}/s', $css, $ruleMatches, PREG_SET_ORDER);
            foreach ($ruleMatches as $match) {
                $selectors = array_map('trim', explode(',', trim($match[1])));
                $declarations = trim($match[2]);

                foreach ($selectors as $selector) {
                    // Only support simple selectors: tag, .class, #id
                    $selector = trim($selector);
                    if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $selector) ||
                        preg_match('/^\.[a-zA-Z][a-zA-Z0-9_-]*$/', $selector) ||
                        preg_match('/^#[a-zA-Z][a-zA-Z0-9_-]*$/', $selector)) {
                        $rules[] = ['selector' => $selector, 'declarations' => $declarations];
                    }
                }
            }
        }

        if (empty($rules)) {
            return $html;
        }

        // Apply inline styles using regex for common patterns
        foreach ($rules as $rule) {
            $selector = $rule['selector'];
            $declarations = self::_cleanDeclarations($rule['declarations']);

            if (str_starts_with($selector, '.')) {
                // Class selector
                $className = substr($selector, 1);
                $html = preg_replace_callback(
                    '/(<[a-zA-Z][^>]*class="[^"]*\b' . preg_quote($className, '/') . '\b[^"]*"[^>]*)(\/?>)/i',
                    function ($match) use ($declarations) {
                        return self::_addInlineStyle($match[1], $declarations) . $match[2];
                    },
                    $html
                );
            } elseif (str_starts_with($selector, '#')) {
                // ID selector
                $id = substr($selector, 1);
                $html = preg_replace_callback(
                    '/(<[a-zA-Z][^>]*id="' . preg_quote($id, '/') . '"[^>]*)(\/?>)/i',
                    function ($match) use ($declarations) {
                        return self::_addInlineStyle($match[1], $declarations) . $match[2];
                    },
                    $html
                );
            } else {
                // Tag selector
                $html = preg_replace_callback(
                    '/(<' . preg_quote($selector, '/') . ')(\s[^>]*)?(\/?>)/i',
                    function ($match) use ($declarations) {
                        $tag = $match[1] . ($match[2] ?? '');
                        return self::_addInlineStyle($tag, $declarations) . $match[3];
                    },
                    $html
                );
            }
        }

        return $html;
    }

    private static function _addInlineStyle(string $tag, string $declarations): string
    {
        if (preg_match('/style="([^"]*)"/', $tag, $match)) {
            $existing = rtrim($match[1], '; ');
            $newStyle = $existing . '; ' . $declarations;
            return preg_replace('/style="[^"]*"/', 'style="' . $newStyle . '"', $tag);
        }

        return $tag . ' style="' . $declarations . '"';
    }

    private static function _cleanDeclarations(string $declarations): string
    {
        $declarations = preg_replace('/\s+/', ' ', $declarations);
        $declarations = trim($declarations, "; \n\r\t");
        return $declarations;
    }
}
