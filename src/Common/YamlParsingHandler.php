<?php

namespace Kaliop\eZP5UI\Common;

use Symfony\Component\Yaml\Parser;

/**
 * A good candidate for a trait, but we harken back to the olde days of php 5.3...
 */
class YamlParsingHandler extends Handler
{
    protected function parseFile($fileName)
    {
        $yaml = new Parser();
        return $yaml->parse(file_get_contents($fileName));
    }

    /**
     * @param string $key uses a shortcut notation to traverse the yml data array: some.nested.key
     * @param string $fileName
     * @param string $delimiter
     * @return mixed
     * @throws \Exception
     */
    protected function getKeyFromFile($key, $fileName, $delimiter='.')
    {
        $values = $this->parseFile($fileName);
        $keyFound = array();
        foreach(explode($delimiter, $key) as $part) {
            if (!is_array($values) || !isset($values[$part])) {
                throw new \Exception("Key '$key' not found in file '$fileName', prefix found: " . ( count($keyFound) ? implode($delimiter, $keyFound) : 'none' ));
            }
            $values = $values[$part];
            $keyFound[] = $part;
        }
        return $values;
    }
}
