<?php

namespace VCSystem;
use LocalFile;

class LocalFileExt extends LocalFile {

    public function loadFromRow($row, $prefix = 'img_') {
        // Клонирование объекта $row
        $clonedRow = clone $row;

        // Вызов родительского метода с клонированным объектом
        parent::loadFromRow($clonedRow, $prefix);

        unset($clonedRow->{"{$prefix}description_id"});
        unset($clonedRow->{"{$prefix}oi_description"});

    }
}
