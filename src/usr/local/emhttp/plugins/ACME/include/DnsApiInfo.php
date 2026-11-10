<?php

/**
 * Parser for `dnsapi_info.txt`, the `dns_*_info` descriptions of the
 * `acme.sh` dnsapi scripts, extracted by `scripts/build_package.sh`.
 *
 * Ported from the JavaScript parser courtesy of
 * https://github.com/yurt-page/acmesh-parse-dnsapi-info, and kept
 * behaviour-compatible with it: the result has the same fields and values.
 *
 * Each provider is a block of lines, separated by an empty line:
 *
 *     dns_cf
 *     CloudFlare
 *     Site: CloudFlare.com
 *     Options:
 *      CF_Key API Key
 *      CF_Email Your account email
 *     OptionsAlt:
 *      CF_Token API Token
 *      CF_Zone_ID Zone ID. Optional.
 */
class DnsApiInfo {
    /**
     * Parse the contents of `dnsapi_info.txt`.
     *
     * @param string $text File contents.
     * @return array[] Providers, in file order. Each has `Id`, `Name`,
     *                 `Description`, `Deprecated`, `Domains`, `Site`, `Docs`,
     *                 `OptsTitle`, `Opts`, `OptsAltTitle`, `OptsAlt`,
     *                 `Issues` and `Author`. Each option in `Opts` and
     *                 `OptsAlt` has `Name`, `Title`, `Description`,
     *                 `Default` and `Optional`.
     */
    static function parse($text) {
        $lines = explode("\n", $text);
        $infos = array();
        $start = 0;
        for ($i = 1; $i < count($lines); $i++) {
            if ($lines[$i] === '') {
                if ($i - $start > 2) {
                    $infos[] = self::parseProvider(array_slice($lines, $start, $i - $start));
                }
                $start = $i + 1;
            }
        }
        return $infos;
    }

    /**
     * Parse the lines of one provider.
     *
     * @param string[] $lines Lines, starting with the provider id.
     * @return array Provider info, see `parse()`.
     */
    private static function parseProvider($lines) {
        $id = array_shift($lines);
        $name = array_shift($lines);
        $description = substr(self::multiLines($lines, ''), 1);
        $deprecated = str_contains($description, 'Deprecated. ');
        if ($deprecated) {
            $description = self::replaceFirst($description, 'Deprecated. ', '');
        }
        [$optsTitle, $opts] = self::parseOptions(self::field($lines, 'Options:'));
        [$optsAltTitle, $optsAlt] = self::parseOptions(self::field($lines, 'OptionsAlt:'));
        return array(
            'Id' => $id,
            'Name' => $name,
            'Description' => $description,
            'Deprecated' => $deprecated,
            'Domains' => self::field($lines, 'Domains:'),
            'Site' => self::toUrl(self::field($lines, 'Site:')),
            'Docs' => self::toUrl(self::field($lines, 'Docs:')),
            'OptsTitle' => $optsTitle,
            'Opts' => $opts,
            'OptsAltTitle' => $optsAltTitle,
            'OptsAlt' => $optsAlt,
            'Issues' => self::toUrl(self::field($lines, 'Issues:')),
            'Author' => self::field($lines, 'Author:'),
        );
    }

    /**
     * Parse an `Options:` or `OptionsAlt:` field.
     *
     * @param string $field Field value: a title line, then one line per
     *                      option, `<name> <title>[. <description>]`.
     * @return array `[title, options]`.
     */
    private static function parseOptions($field) {
        if ($field === '') {
            return array('', array());
        }
        $lines = explode("\n", $field);
        $title = array_shift($lines);
        $opts = array();
        foreach ($lines as $line) {
            $posName = strpos($line, ' ');
            if ($posName === false || $posName === 0) {
                continue;
            }
            $opt = array(
                'Name' => substr($line, 0, $posName),
                'Title' => '',
                'Description' => '',
                'Default' => '',
                'Optional' => false,
            );
            $posTitle = strpos($line, '. ');
            if ($posTitle === false || $posTitle === 0) {
                $opt['Title'] = substr($line, $posName + 1);
            } else {
                $opt['Title'] = self::jsSubstring($line, $posName + 1, $posTitle);
                $description = substr($line, $posTitle);
                $opt['Optional'] = str_contains($description, ' Optional.');
                if ($opt['Optional']) {
                    $description = self::replaceFirst($description, ' Optional.', '');
                }
                $defaultMarker = ' Default: "';
                $defaultPos = strpos($description, $defaultMarker);
                if ($defaultPos !== false) {
                    $opt['Optional'] = true;
                    $defaultEnd = strpos($description, '".', $defaultPos + 1);
                    $opt['Default'] = self::jsSubstring($description, $defaultPos + strlen($defaultMarker), $defaultEnd === false ? -1 : $defaultEnd);
                    $description = substr($description, 0, $defaultPos);
                }
                if (str_starts_with($description, '. ')) {
                    $description = substr($description, 2);
                } elseif ($description === '.') {
                    $description = '';
                }
                $opt['Description'] = $description;
            }
            $opts[] = $opt;
        }
        return array($title, $opts);
    }

    /**
     * Get a field's value: the rest of the first line starting with
     * `$name`, plus any following indented lines.
     *
     * @param string[] $lines Provider lines.
     * @param string $name Field name, including the colon.
     * @return string Value, or an empty string if there is no such field.
     */
    private static function field($lines, $name) {
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, $name)) {
                $next = array_slice($lines, $i + 1);
                return self::multiLines($next, self::trim(substr($line, strlen($name))));
            }
        }
        return '';
    }

    /**
     * Append the leading indented lines of `$lines` to `$value`, one per
     * line, and remove them from `$lines`.
     *
     * @param string[] $lines Lines, modified.
     * @param string $value Value so far.
     * @return string Value.
     */
    private static function multiLines(&$lines, $value) {
        while (count($lines) > 0 && str_starts_with($lines[0], ' ')) {
            $value .= "\n" . self::trim(array_shift($lines));
        }
        return $value;
    }

    /** Prefix `https://` unless already present. */
    private static function toUrl($url) {
        return $url !== '' && !str_starts_with($url, 'https://') ? "https://$url" : $url;
    }

    /** Replace the first occurrence of `$search`, like JavaScript's `String.replace()`. */
    private static function replaceFirst($subject, $search, $replace) {
        $pos = strpos($subject, $search);
        return $pos === false ? $subject : substr_replace($subject, $replace, $pos, strlen($search));
    }

    /**
     * Same as JavaScript's `String.substring()`: negative indexes count as 0,
     * and the indexes are swapped if `$end < $start`.
     */
    private static function jsSubstring($string, $start, $end) {
        $start = max(0, $start);
        $end = max(0, $end);
        if ($end < $start) {
            [$start, $end] = array($end, $start);
        }
        return substr($string, $start, $end - $start);
    }

    /** Same as JavaScript's `String.trim()` for the whitespace used in the info file. */
    private static function trim($string) {
        return trim($string, " \t\n\r\v\f");
    }
}
?>
