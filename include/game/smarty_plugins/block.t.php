<?php
// Simple {t}{/t} block for translation via T_() or gettext()
function smarty_block_t($params, $content, Smarty_Internal_Template $template, &$repeat)
{
    if ($repeat) return;                 // only output on closing tag
    if ($content === null) return '';
    if (!function_exists('T_')) {
        // fallback to gettext() if you don't have T_()
        if (function_exists('_')) return htmlspecialchars(_($content), ENT_QUOTES, 'UTF-8');
        return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars(T_($content), ENT_QUOTES, 'UTF-8');
}
