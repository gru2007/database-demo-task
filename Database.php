<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    protected array $i18n_error_messages = [
        1 => 'попытка указать для заполнителя типа "%s" значение типа "%s" в шаблоне запроса "%s"',
        2 => 'Два символа `.` идущие подряд в имени столбца или таблицы',
    ];

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        return $this->parse($query, $args);
    }

    private function createErrorMessage(string $type, mixed $value, string $original_query): string
    {
        return sprintf($this->i18n_error_messages[1], $type, gettype($value), $original_query);
    }

    private function parse(string $query, array $args, ?string $original_query = null): string
    {
        $original_query = $original_query ?? $query;

        $offset = 0;

        while (($posQM = mb_strpos($query, '?', $offset)) !== false) {
            $offset = $posQM;

            $placeholder_type = mb_substr($query, $posQM + 1, 1);

            $value = array_shift($args);

            $is_undefined = false;

            // Любые ситуации с нахождением знака вопроса.
            if ($placeholder_type == '' || !in_array($placeholder_type, array('d', 's', 'n', 'a', 'f', '#'))) {
                if (is_string($value)) {
                    $is_undefined = true;
                    $placeholder_type = 's';
                } else if (is_integer($value)) {
                    $is_undefined = true;
                    $placeholder_type = 'd';
                } else if (is_double($value)){
                    $is_undefined = true;
                    $placeholder_type = 'f';
                } else if (is_bool($value)) {
                    $is_undefined = true;
                    $placeholder_type = 's';
                } else if (is_null($value)) {
                    $is_undefined = true;
                    $placeholder_type = 'n';
                } else {
                    $offset += 1;
                    continue;
                }
            }
            if ($value === 'THIS_BLOCK_NEED_TO_BE_DELETED') {
                while ((mb_strpos($query,'{')) !== false) {
                    $query = static::mb_substr_replace($query, '', mb_strpos($query,'{'), mb_strpos($query,'}')-mb_strpos($query,'{')+1);
                    $offset += mb_strlen($value);
                }
                return $this->parse($query, array_diff( $args, ['THIS_BLOCK_NEED_TO_BE_DELETED'] ) );
            }

            if (is_null($value)) {
                $placeholder_type = 'n';
            }

            $is_associative_array = false;

            switch ($placeholder_type) {
                // Simple string escaping
                case 's':
                    $value = $this->getValueStringType($value, $original_query);
                    $value = $this->mysqlRealEscapeString($value);
                    $query = !empty($is_undefined) ? static::mb_substr_replace($query, $value, $posQM, 1) : static::mb_substr_replace($query, $value, $posQM, 2);
                    $offset += mb_strlen($value);
                    break;

                // Integer
                case 'd':
                    $value = $this->getValueIntType($value, $original_query);
                    $query = !empty($is_undefined) ? static::mb_substr_replace($query, $value, $posQM, 1) : static::mb_substr_replace($query, $value, $posQM, 2);
                    $offset += mb_strlen($value);
                    break;

                // float
                case 'f':
                    $value = $this->getValueFloatType($value, $original_query);
                    $query = !empty($is_undefined) ? static::mb_substr_replace($query, $value, $posQM, 1) : static::mb_substr_replace($query, $value, $posQM, 2);
                    $offset += mb_strlen($value);
                    break;

                // NULL insert
                case 'n':
                    $value = $this->getValueNullType($value, $original_query);
                    $query = !empty($is_undefined) ? static::mb_substr_replace($query, $value, $posQM, 1) : static::mb_substr_replace($query, $value, $posQM, 2);
                    $offset += mb_strlen($value);
                    break;

                // Парсинг массивов.
                // Simple array
                case 'a':
                    $value = $this->getValueArrayType($value, $original_query);

                    $next_char = mb_substr($query, $posQM + 2, 1);

                    $parts = array();

                    foreach ($value as $key => $val) {
                        if (is_string($val)) {
                            $val = $this->getValueStringType($val, $original_query);
                            $val = $this->mysqlRealEscapeString($val);
                        } else if (is_integer($val)) {
                            $val = $this->getValueIntType($val, $original_query);
                        } else if (is_double($val)){
                            $value = $this->getValueFloatType($val, $original_query);
                        } else if (is_bool($val)) {
                            $val = $this->getValueStringType($val, $original_query);
                        } else if (is_null($val)) {
                            $val = $this->getValueNullType($val, $original_query);
                        } 

                        if (!array_is_list($value)) {
                            $parts[] = $this->escapeFieldName($key, $original_query) . ' = ' . $val;
                        } else {
                            if (!is_null($val) && !is_int($val) && !is_float($val) && is_bool($val)) {
                                $parts[] = "'" . $val . "'";
                            } else {
                                $parts[] = $val;
                            }
                        }
                    }

                    $value = implode(', ', $parts);
                    $value = $value !== '' ? $value : 'NULL';

                    $query = static::mb_substr_replace($query, $value, $posQM, 2);
                    $offset += mb_strlen($value);

                    break;

                // Name Table array
                case '#':
                    if (is_array($value)) {
                        $value = $this->getValueArrayType($value, $original_query);

                        $next_char = mb_substr($query, $posQM + 2, 1);

                        $parts = array();

                        foreach ($value as $key => $val) {
                            if (is_string($val)) {
                                $val = $this->escapeFieldName($val, $original_query);
                            } else if (is_null($val)) {
                                $val = $this->getValueNullType($val, $original_query);
                            } 

                            if (!is_null($val)) {
                                $parts[] = $val;
                            }
                        }

                        $value = implode(', ', $parts);
                        $value = $value !== '' ? $value : 'NULL';

                        $query = static::mb_substr_replace($query, $value, $posQM, 2);
                        $offset += mb_strlen($value);

                        break;
                    } else {
                        $value = $this->escapeFieldName($value, $original_query);
                        $query = static::mb_substr_replace($query, $value, $posQM, 2);
                        $offset += mb_strlen($value);
                        break;
                    }
            }
        }

        return str_replace('}','',str_replace('{','',$query));
    }

    private function getValueStringType(mixed $value, string $original_query): string
    {

        if (is_bool($value)) {
            return (string) (int) $value;
        }

        if (!is_string($value) && !(is_numeric($value) || is_null($value))) {
            throw new Exception($this->createErrorMessage('string', $value, $original_query)
            );
        }

        return (string) $value;
    }

    private function getValueIntType(mixed $value, string $original_query): mixed
    {
        if (is_null($value)) {
            return 0;
        }
        if ($this->isInteger($value)) {
            return $value;
        }

        if ($this->isFloat($value) || is_null($value) || is_bool($value)) {
            return (int) $value;
        }
    }

    private function getValueFloatType(mixed $value, string $original_query): mixed
    {
        if ($this->isFloat($value)) {
            return $value;
        }

		 if ($this->isInteger($value) || is_null($value) || is_bool($value)) {
            return (float) $value;
        }

        return 0;

    }

    private function getValueNullType(mixed $value, string $original_query): string
    {
        return 'NULL';
    }

    private function getValueArrayType(mixed $value, string $original_query): array
    {
        if (!is_array($value)) {
            throw new Exception( $this->createErrorMessage('array', $value, $original_query)
            );
        }

        return $value;
    }

    /**
     * Экранирует имя поля таблицы или столбца.
     */
    private function escapeFieldName(mixed $value, string $original_query): string
    {
        if (!is_string($value)) {
            throw new Exception(
                $this->createErrorMessage('field', $value, $original_query)
            );
        }

        $new_value = '';

        $replace = function($value){
            return '`' . str_replace("`", "``", $value) . '`';
        };

        $dot = false;

        if ($values = explode('.', $value)) {
            foreach ($values as $value) {
                if ($value === '') {
                    if (!$dot) {
                        $dot = true;
                        $new_value .= '.';
                    } else {
                        throw new Exception(
                            $this->i18n_error_messages[2]
                        );
                    }
                } else {
                    $new_value .= $replace($value) . '.';
                }
            }

            return rtrim($new_value, '.');
        } else {
            return $replace($value);
        }
    }

    public function skip()
    {
        return 'THIS_BLOCK_NEED_TO_BE_DELETED';
    }

    public function str_replace_first($search, $replace, $subject)
    {
        $search = '/'.preg_quote($search, '/').'/';
        return preg_replace($search, $replace, $subject, 1);
    }

	 /**
     * Проверяет, является ли значение целым числом, умещающимся в диапазон PHP_INT_MAX.
     */
    private function isInteger(mixed $val): bool
    {
        if (!is_scalar($val) || is_bool($val)) {
            return false;
        }

        return !$this->isFloat($val) && preg_match('~^((?:\+|-)?[0-9]+)$~', $val) === 1;
    }

    /**
     * Проверяет, является ли значение числом с плавающей точкой.
     */
    private function isFloat(mixed $val): bool
    {
        if (!is_scalar($val) || is_bool($val)) {
            return false;
        }

        return gettype($val) === "double" || preg_match("/^([+-]*\\d+)*\\.(\\d+)*$/", $val) === 1;
    }

    /**
     * Заменяет часть строки string, начинающуюся с символа с порядковым номером start
     * и (необязательной) длиной length, строкой replacement и возвращает результат.
     */
    private static function mb_substr_replace($string, $replacement, $start, $length = null, $encoding = null): string
    {
        if ($encoding == null) {
            $encoding = mb_internal_encoding();
        }

        if ($length == null) {
            return mb_substr($string, 0, $start, $encoding) . $replacement;
        } else {
            if ($length < 0) {
                $length = mb_strlen($string, $encoding) - $start + $length;
            }

            return
                mb_substr($string, 0, $start, $encoding) .
                $replacement .
                mb_substr($string, $start + $length, mb_strlen($string, $encoding), $encoding);
        }
    }
    /**
     * Экранирует специальные символы в строке для использования в SQL выражении,
     * используя текущий набор символов соединения.
     */
    private function mysqlRealEscapeString($value): string
    {
        return "'".$this->mysqli->real_escape_string($value)."'";
    }
}
