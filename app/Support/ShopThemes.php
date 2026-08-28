<?php

namespace App\Support;

/**
 * The 4 selectable shop appearance themes. Each defines a background and
 * sidebar color plus a few accent colors; text/icon colors on top of the
 * background and sidebar are derived automatically (see contrastColor())
 * rather than specified per theme, so every combination stays readable.
 */
class ShopThemes
{
    public const DEFAULT = 'theme-1';

    public static function definitions(): array
    {
        return [
            'theme-1' => [
                'label' => 'Amber Walnut',
                'background' => '#EBEFEE',
                'sidebar' => '#BB6C43',
                'accent' => '#BB6C43',
                'accentSoft' => '#CCB499',
                'accentAlt' => '#C8906D',
                'ink' => '#4A413C',
            ],
            'theme-2' => [
                'label' => 'Terracotta Olive',
                'background' => '#D8D4BC',
                'sidebar' => '#B8210F',
                'accent' => '#DC8236',
                'accentSoft' => '#E76814',
                'accentAlt' => '#891A10',
                'ink' => '#714236',
            ],
            'theme-3' => [
                'label' => 'Citrus Pop',
                'background' => '#FCEFC3',
                'sidebar' => '#EB4203',
                'accent' => '#00CEC8',
                'accentSoft' => '#FF9C5F',
                'accentAlt' => '#EB4203',
                'ink' => '#4A413C',
            ],
            'theme-4' => [
                'label' => 'Coral Slate',
                'background' => '#FAFAFA',
                'sidebar' => '#EC4D25',
                'accent' => '#2BCFCE',
                'accentSoft' => '#CDCDCF',
                'accentAlt' => '#939599',
                'ink' => '#3A3A3C',
            ],
            // The one 'flat' theme: a white-sidebar, opaque-card SaaS-dashboard
            // look, distinct enough from the other 4 (which share one frosted-
            // glass card style across different color palettes) that it needs
            // its own structural CSS, not just different colors — see the
            // 'style' key and the extra variables cssVariables() only emits
            // when style === 'flat'.
            'theme-5' => [
                'label' => 'Indigo Clarity',
                'style' => 'flat',
                'background' => '#F6F5FC',
                'sidebar' => '#FFFFFF',
                'accent' => '#6D5DF6',
                'accentSoft' => '#EEECFD',
                'accentAlt' => '#5949E0',
                'ink' => '#20232C',
            ],
        ];
    }

    public static function get(string $key): array
    {
        return self::definitions()[$key] ?? self::definitions()[self::DEFAULT];
    }

    public static function style(string $key): string
    {
        return self::get($key)['style'] ?? 'glass';
    }

    /**
     * CSS custom properties for the given theme key, ready to render into a
     * <style> block. Text colors are computed, not stored, so the 4 themes
     * only ever need to declare their brand colors.
     */
    /**
     * CSS custom properties for the given theme key, ready to render into a
     * <style> block. Every variant needed by the layout/nav (full-strength
     * text, muted text, active/hover washes) is pre-baked here as a
     * complete rgba() string, rather than leaning on Tailwind's arbitrary-
     * value opacity modifier on a CSS var — that trick needs Tailwind
     * 3.4+'s color-mix() support, which this project's ^3.1.0 install may
     * predate.
     */
    public static function cssVariables(string $key): array
    {
        $theme = self::get($key);
        $isFlat = ($theme['style'] ?? 'glass') === 'flat';

        $bgRgb = self::contrastRgb($theme['background']);
        $sbRgb = self::contrastRgb($theme['sidebar']);
        $acRgb = self::contrastRgb($theme['accent']);

        $vars = [
            '--theme-bg' => $theme['background'],
            '--theme-bg-text' => "rgba({$bgRgb}, 1)",
            '--theme-sidebar' => $theme['sidebar'],
            '--theme-sidebar-text' => "rgba({$sbRgb}, 1)",
            '--theme-sidebar-text-muted' => "rgba({$sbRgb}, 0.7)",
            '--theme-sidebar-hover-bg' => "rgba({$sbRgb}, 0.1)",
            '--theme-sidebar-border' => "rgba({$sbRgb}, 0.15)",
            '--theme-accent' => $theme['accent'],
            '--theme-accent-text' => "rgba({$acRgb}, 1)",
            '--theme-accent-soft' => $theme['accentSoft'],
            '--theme-accent-alt' => $theme['accentAlt'],
            '--theme-ink' => $theme['ink'],
            // Active sidebar item background/text — a separate pair from
            // --theme-sidebar-text so the 'flat' theme can color the active
            // item with the accent (an indigo pill) without recoloring the
            // shop name/logo text in the sidebar header, which stays on
            // --theme-sidebar-text. For the 3 glass themes this is just the
            // same wash the sidebar itself already used, unchanged.
            '--theme-sidebar-active-bg' => $isFlat ? $theme['accentSoft'] : "rgba({$sbRgb}, 0.16)",
            '--theme-sidebar-active-text' => $isFlat ? $theme['accent'] : "rgba({$sbRgb}, 1)",
        ];

        if ($isFlat) {
            // Only the flat theme sets these — layouts/tenant.blade.php's
            // [data-theme-style="flat"] CSS block is the only thing that
            // reads them, so the 4 glass themes (and Super Admin's own
            // walnut-only panel, which never renders shop theme variables
            // at all) are completely unaffected.
            $vars['--theme-font'] = "'Roboto', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Arial, sans-serif";
            $vars['--theme-card-bg'] = '#FFFFFF';
            $vars['--theme-card-border'] = '#EAE8F4';
            $vars['--theme-card-shadow'] = '0 1px 2px rgba(16,24,40,.04), 0 8px 20px -4px rgba(16,24,40,.07)';
            $vars['--theme-table-header-bg'] = '#FAFAFE';
            $vars['--theme-table-header-text'] = '#8B8D9B';
        }

        return $vars;
    }

    /**
     * The "r, g, b" triplet of whichever reads better on top of $hex —
     * white or dark ink — per the "font should be opposite the surface"
     * rule (dark surface -> light text, light surface -> dark text).
     */
    public static function contrastRgb(string $hex): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '31, 41, 55' : '255, 255, 255';
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
