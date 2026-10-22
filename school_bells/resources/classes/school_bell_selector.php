<?php
class school_bell_selector {

    private $min, $hou, $day;

    function __construct($time12h = False) {

        # Fill min
        $this->min = array();
        $this->min[-1] = "*";
        for ($i = 0; $i <= 59; $i++) {
            $this->min[$i] = sprintf("%1$02d", $i);
        }
        
        # Fill hou
        $this->hou = array();
        $this->hou[-1] = "*";
        if ($time12h) {
            $this->hou[0] = "12 AM";
            for ($i = 1; $i <= 12; $i++) {
                $this->hou[$i] = sprintf("%1$02d", $i) . " AM";
            }
            for  ($i = 13; $i <= 23; $i++) {
                $this->hou[$i] = sprintf("%1$02d", $i - 12) . " PM";
            }
        } else {
            for ($i = 0; $i <= 23; $i++) {
                $this->hou[$i] = sprintf("%1$02d", $i);
            }
        }

        $this->day = array();
        $this->day[-1] = "*";
        for ($i = 1; $i <= 31; $i++) {
            $this->day[$i] = sprintf("%1$02d", $i);
        }

        # Fill dow
        if ($time12h) {
            $this->dow = array(
                127  => '*',
                64   => 'Sunday',
                1   => 'Monday',
                2   => 'Tuesday',
                4   => 'Wednesday',
                8   => 'Thursday',
                16   => 'Friday',
                32   => 'Saturday',
            );
        } else {
            $this->dow = array(
                127  => '*',
                1   => 'Monday',
                2   => 'Tuesday',
                4   => 'Wednesday',
                8   => 'Thursday',
                16   => 'Friday',
                32   => 'Saturday',
                64   => 'Sunday'
            );
        }

        # Fill month
        $this->mon = array(
            -1  => '*',
            1   => 'January',
            2   => 'February',
            3   => 'March',
            4   => 'April',
            5   => 'May',
            6   => 'June',
            7   => 'July',
            8   => 'August',
            9   => 'September',
            10  => 'October',
            11  => 'November',
            12  => 'December'
        );
    }

    private function _option_string($option_item, $draw_item, $selected_item) {

        if ($option_item == $selected_item) {
            return "<option selected value='" . $option_item . "'>" . $draw_item . "</option>\n";
        }
        return "<option value='" . $option_item . "'>" . $draw_item . "</option>\n";
    }

    private function _option_string_multiple($option_item, $draw_item, $selected_items) {

        if (($option_item & $selected_items) == $option_item) {
            return "<option selected value='" . $option_item . "'>" . $draw_item . "</option>\n";
        }
        return "<option value='" . $option_item . "'>" . $draw_item . "</option>\n";
    }

    private function _draw_selected($selector_name ,$range_array, $selected) {
        $selector_text = "<select name = '$selector_name' id = '$selector_name' class='formfld'>\n";

        foreach ($range_array as $option_item => $draw_item) {
            $selector_text .= $this->_option_string($option_item, $draw_item, $selected);
        }
        $selector_text .= "</select>";
        return $selector_text;
    }

    private function _draw_selected_multiple($selector_name ,$range_array, $selected) {
        $selected = (int)$selected;
        $id = htmlspecialchars($selector_name, ENT_QUOTES);
        // A plain <select multiple> is kept as the no-JavaScript fallback and as the submitted field.
        // JavaScript hides it and renders a list of checkboxes that stays in sync with its options.
        $selector_text = "<select name='" . $id . "[]' id='" . $id . "' class='formfld' multiple='multiple' size='" . count($range_array) . "'>\n";

        foreach ($range_array as $option_item => $draw_item) {
            $selector_text .= $this->_option_string_multiple($option_item, htmlspecialchars($draw_item, ENT_QUOTES), $selected);
        }
        $selector_text .= "</select>\n";
        $selector_text .= "<div id='" . $id . "_checkboxes' class='formfld' style='display: none; height: auto; padding: 4px 8px;'></div>\n";
        $selector_text .= $this->_checkbox_multiselect_script($selector_name, array_search('*', $range_array, true));
        return $selector_text;
    }

    private function _checkbox_multiselect_script($selector_name, $all_value = false) {
        $json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        $id_json = json_encode($selector_name, $json_flags);
        $all_json = json_encode($all_value === false ? null : (string)$all_value, $json_flags);
        return "<script type='text/javascript'>\n" .
            "(function() {\n" .
            "    var select = document.getElementById(" . $id_json . ");\n" .
            "    var container = document.getElementById(" . $id_json . " + '_checkboxes');\n" .
            "    if (!select || !container) { return; }\n" .
            "    var checkboxes = [];\n" .
            "    var all_value = " . $all_json . ";\n" .
            "    function sync() {\n" .
            "        var all_checked = false;\n" .
            "        for (var i = 0; i < checkboxes.length; i++) {\n" .
            "            if (checkboxes[i].value === all_value && checkboxes[i].checked) { all_checked = true; }\n" .
            "        }\n" .
            "        for (var i = 0; i < checkboxes.length; i++) {\n" .
            "            select.options[i].selected = all_checked ? checkboxes[i].value === all_value : checkboxes[i].checked;\n" .
            "        }\n" .
            "    }\n" .
            "    for (var i = 0; i < select.options.length; i++) {\n" .
            "        var option = select.options[i];\n" .
            "        var label = document.createElement('label');\n" .
            "        label.style.display = 'block';\n" .
            "        label.style.whiteSpace = 'nowrap';\n" .
            "        var checkbox = document.createElement('input');\n" .
            "        checkbox.type = 'checkbox';\n" .
            "        checkbox.value = option.value;\n" .
            "        checkbox.id = select.id + '_' + option.value;\n" .
            "        checkbox.checked = option.selected;\n" .
            "        checkbox.onchange = function() {\n" .
            "            if (this.value === all_value) {\n" .
            "                for (var j = 0; j < checkboxes.length; j++) { checkboxes[j].checked = this.checked; }\n" .
            "            } else if (!this.checked) {\n" .
            "                for (var j = 0; j < checkboxes.length; j++) {\n" .
            "                    if (checkboxes[j].value === all_value) { checkboxes[j].checked = false; }\n" .
            "                }\n" .
            "            }\n" .
            "            sync();\n" .
            "        };\n" .
            "        checkboxes.push(checkbox);\n" .
            "        label.appendChild(checkbox);\n" .
            "        label.appendChild(document.createTextNode(' ' + option.text));\n" .
            "        container.appendChild(label);\n" .
            "    }\n" .
            "    select.style.display = 'none';\n" .
            "    container.style.display = 'inline-block';\n" .
            "})();\n" .
            "</script>\n";
    }

    public function draw_min($name, $selected = '') {
        return $this->_draw_selected($name, $this->min, $selected);
    }

    public function draw_hou($name, $selected = '') {
        return $this->_draw_selected($name, $this->hou, $selected);
    }

    public function draw_dom($name, $selected = '') {
        return $this->_draw_selected($name ,$this->day, $selected);
    }

    public function draw_mon($name, $selected = '') {
        return $this->_draw_selected($name ,$this->mon, $selected);
    }

    public function draw_dow($name, $selected = '') {
        return $this->_draw_selected_multiple($name, $this->dow, $selected);
    }
}
?>