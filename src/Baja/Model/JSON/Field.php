<?php

namespace Baja\Model\JSON;

class Field
{
    /** @var  string */
    private $type;
    /** @var  string */
    private $name;
    /** @var  string */
    private $code;
    /** @var  string */
    private $pass;
    /** @var  string */
    private $xor;
    /** @var  int */
    private $precision;
    /** @var  bool */
    private $negative;
    /** @var  bool */
    private $multiple;
    /** @var  string[] */
    private $options;

    /**
     * @return string
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPass()
    {
        return $this->pass;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }


    /**
     * Campo constructor.
     * @param $code
     * @param $name
     * @param string $pass
     * @param string $xor
     */
    public function __construct($code, $name, $pass = '1', $xor = 'X')
    {
        $this->type = "hidden";
        $this->name = $name;
        $this->code = $code;
        $this->pass = $pass === null ? '1' : $pass;
        $this->xor = $xor === null ? 'X' : $xor;
    }

    public function setNumber($precision = 0, $negative = false)
    {
        $this->type = "number";
        $this->precision = $precision;
        $this->negative = $negative;
    }

    public function setEnum($options = [], $multiple = false) {
        $this->type = "enum";
        $this->options = $options;
        $this->multiple = $multiple;
    }

    public function setTime() {
        $this->type = "time";
    }

    /**
     * @param null $value
     * @param bool $canJudge
     * @return string
     */
    public function printSelf($value = null, $canJudge = true)
    {
        $ret = "";
        switch ($this->type) {
            default:
            case "number":
                $ret .= '<td><label for="' . $this->code . '">' . $this->name . '</label></td>';
                $ret .= '<td style="width: 80px"><input data-group="' . $this->pass . '" data-xor="' . $this->xor . '" style="width: 100%; font: 20px/20px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" type="number" ' . ($this->negative ? 'max="0"' : 'min="0"') . ' step="' . (1 / pow(10, $this->precision)) . '" name="' . $this->code . '" id="' . $this->code . '" ' . ($value !== null ? 'value="' . $value . '" ' . ($canJudge ? '' : 'disabled') : '') . ' /></td>';
                break;
            case "enum":
                $valArray = explode(',', $value);
                $ret .= '<td><label for="' . $this->code . '">' . $this->name . '</label></td>';
                $ret .= '<td style="width: 120px">
                        <select ' . ($this->multiple ? 'multiple' : '') . ' data-group="' . $this->pass . '" data-xor="' . $this->xor . '" style="width: 100%; font: 12px/12px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" name="' . $this->code . ($this->multiple ? '[]' : '') . '" id="' . $this->code . '" ' . ($value !== null ? ($canJudge ? '' : 'disabled') : '') . ' />
                            <option value=""></option>';
                foreach ($this->options as $o) {
                    $ret .= '<option value="' . $o . '" ' . (array_search($o, $valArray) !== false ? 'selected' : '') . '>' . $o . '</option>';
                }
                $ret .= '</select>
                     </td>';
                break;
            case "time":
                $ret .= '<td><label for="' . $this->code . '">' . $this->name . '</label></td>';
                $ret .= '<td style="width: 80px"><input data-group="' . $this->pass . '" data-xor="' . $this->xor . '" style="width: 100%; font: 20px/20px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" name="' . $this->code . '" type="time" id="' . $this->code . '" ' . ($value !== null ? 'value="' . $value . '" ' . ($canJudge ? '' : 'disabled') : '') . ' /></td>';
                break;
            case "hidden":
                $ret .= '<input type="hidden" name="' . $this->code . '" id="' . $this->code . '" value="' . $value . '" />';
                break;
        }
//        if ($this->name && $this->precision >= 0) {
//        } else if ($this->name && $this->precision == -1) {
//            $ret .= '<td><label for="' . $this->code . '">' . $this->name . '</label></td>';
//            $ret .= '<td style="width: 120px">
//                        <select data-group="' . $this->pass . '" data-xor="' . $this->xor . '" style="width: 100%; font: 20px/20px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" name="' . $this->code . '" id="' . $this->code . '" ' . ($value !== null ? ($canJudge ? '' : 'disabled') : '') . ' />
//                            <option value=""></option>
//                            <option value="Aprovado" ' . ($value == 'Aprovado' ? 'selected' : '') . '>Aprovado</option>
//                            <option value="Reprovado" ' . ($value == 'Reprovado' ? 'selected' : '') . '>Reprovado</option>
//                        </select>
//                     </td>';
//        } else if ($this->name && $this->precision == -2) {
//            $ret .= '<td><label for="'.$this->code.'">'.$this->name.'</label></td>';
//            $ret .= '<td style="width: 120px">
//                        <select multiple data-group="'.$this->pass.'" data-xor="'.$this->xor.'" style="width: 100%; font: 12px/12px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" name="'.$this->code.'" id="'.$this->code.'" '.($value !== null ? ($canJudge ? '' : 'disabled') : '').' />
//                            <option value="-" '.($value == '-' ? 'selected' : '').'>-</option>';
//            $valArray = explode(', ', $value);
//            for ($i = 0; $i <=14; $i++) {
//                $ret .= '<option value="'.$i.'" ' . (array_search("$i", $valArray) !== false ? 'selected' : '') . '>'.$i.'</option>';
//            }
//            $ret .= '</select>
//                     </td>';
//        } else if ($this->name && $this->precision == -3) {
//            $ret .= '<td><label for="'.$this->code.'">'.$this->name.'</label></td>';
//            $ret .= '<td style="width: 120px">
//                        <select data-group="'.$this->pass.'" data-filter="'.$this->xor.'" style="width: 100%; font: 20px/20px \'Trebuchet MS\', Arial , Sans-serif; border: 0; margin: 0; padding: 0" name="'.$this->code.'" id="'.$this->code.'" '.($value !== null ? ($canJudge ? '' : 'disabled') : '').' />
//                            <option value=""></option>
//                            <option value="0" '.($value == '0' ? 'selected' : '').'>Trecho 1</option>
//                            <option value="1" '.($value == '1' ? 'selected' : '').'>Trecho 2</option>
//                            <option value="2" '.($value == '2' ? 'selected' : '').'>Trecho 3</option>
//                            <option value="3" '.($value == '3' ? 'selected' : '').'>Completou</option>
//                        </select>
//                     </td>';
//        } else {
//
//        }
        return $ret;
    }
}