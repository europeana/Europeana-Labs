<?php

namespace Sirius\Validation\Rule\Upload;

use Sirius\Validation\Rule\AbstractValidator;

class Image extends AbstractValidator
{

    const OPTION_ALLOWED_IMAGES = 'allowed';

    protected static $defaultMessageTemplate = 'File is not a valid image (only {image_types} are allowed)';

    protected $options = array(
        self::OPTION_ALLOWED_IMAGES => array('jpg', 'png', 'gif')
    );

    protected $imageTypesMap = array(
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_JPEG2000 => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_PSD => 'psd',
        IMAGETYPE_BMP => 'bmp',
        IMAGETYPE_ICO => 'ico',
    );

    function setOption($name, $value)
    {
        if ($name == self::OPTION_ALLOWED_IMAGES) {
            if (is_string($value)) {
                $value = explode(',', $value);
            }
            $value = array_map('trim', $value);
            $value = array_map('strtolower', $value);
        }
        return parent::setOption($name, $value);
    }

    function validate($value, $valueIdentifier = null)
    {
        $this->value = $value;
        if (!is_array($value) || !isset($value['tmp_name']) || !file_exists($value['tmp_name'])) {
            $this->success = false;
        } else {
            $imageInfo = getimagesize($value['tmp_name']);
            $extension = isset($this->imageTypesMap[$imageInfo[2]]) ? $this->imageTypesMap[$imageInfo[2]] : false;
            $this->success = ($extension && in_array($extension, $this->options[self::OPTION_ALLOWED_IMAGES]));
        }
        return $this->success;
    }

    function getPotentialMessage()
    {
        $message = parent::getPotentialMessage();
        $imageTypes = array_map('strtoupper', $this->options[self::OPTION_ALLOWED_IMAGES]);
        $message->setVariables(
            array(
                'image_types' => implode(', ', $imageTypes)
            )
        );
        return $message;
    }
}
