<?php namespace PdoPlus;

/**
 * @internal
 */
abstract class Util {

    public static function is_assoc($arr) {
        $i = 0;
        foreach($arr as $k => $_) {
            if($k !== $i++) return true;
        }
        return false;
    }

    public static function pprint_cli($value, $max=3, $depth=0) {
        if(is_null($value)) echo 'null';
        elseif(is_resource($value)) echo strval($value);
        elseif(is_string($value)) {
            $double_quote_count = substr_count($value, '"');
            $single_quote_count = substr_count($value, "'");
            $value = str_replace('\\','\\\\',$value);
            if($single_quote_count < $double_quote_count) {
                $value = "'".str_replace("'","\\'",$value)."'";
            } else {
                $value = '"'.str_replace('"','\"',$value).'"';
            }
            echo $value;
        }
        elseif(is_bool($value)) echo ($value?'true':'false');
        elseif(is_int($value)||is_float($value)) echo $value;
        elseif(is_array($value)) {
            echo 'array(';
            if(empty($value)) {
                echo ')';
            } else {
                if(is_null($max)||$max<0||$depth<$max) {
                    foreach($value as $k=>$v) {
                        echo PHP_EOL.str_repeat(' ', ($depth+1)*4).$k.' => ';
//                        if($k === 'GLOBALS') echo '**GLOBALS**';
//						if(is_string($v) && (strpos($k,'password')!==false||strpos($k,'secret')!==false)) $v = '*************';
                        self::pprint_cli($v, $max, $depth+1);
                    }
                    echo PHP_EOL.str_repeat(' ', $depth*4).')';
                } else {
                    echo '...)';
                }
            }
        }
        elseif(is_object($value)||is_a($value,'__PHP_Incomplete_Class')) {
            echo ''.get_class($value).'{';
            if(is_null($max)||$max<0||$depth<$max) {
                foreach($value as $k=>$v) {
                    echo PHP_EOL.str_repeat(' ', ($depth+1)*4).$k.' = ';
//					if(is_string($v) && (strpos($k,'password')!==false||strpos($k,'secret')!==false)) $v = '*************';
                    self::pprint_cli($v, $max, $depth+1);
                }
                echo PHP_EOL.str_repeat(' ', $depth*4).'}';
            } else {
                echo '...}';
            }

        }
        else echo gettype($value);
        if($depth==0) echo PHP_EOL;
    }

    /**
     * Push an element into a sub-array.
     *
     * @param array $array
     * @param string|array $key
     * @param mixed $var
     */
    public static function array_push(&$array, $key, $var=null) {
        if(func_num_args() === 3) {
            if(is_array($key)) {
                while(count($key) >= 2) {
                    $k = array_shift($key);
                    if(!array_key_exists($k, $array)) {
                        $array[$k] = [];
                    }
                    $array = &$array[$k];
                }
                $key = reset($key);
            }
            if(array_key_exists($key, $array)) {
                $array[$key][] = $var;
            } else {
                $array[$key] = [$var];
            }
        } else {
            $array[] = $key;
        }
    }
}