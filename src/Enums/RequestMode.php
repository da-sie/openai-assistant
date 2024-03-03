<?php

namespace DaSie\Openaiassistant\Enums;

enum RequestMode: string
{
    case json = 'json';
    case text = 'text';

    public function toText(): string
    {
        return match ($this) {
            self::json => 'JSON',
            self::text => 'Text',
        };
    }

    public function file_extension(): string
    {
        return match ($this) {
            self::text => 'txt',
            self::json => 'json',
        };
    }

    public function prePrompt(): string
    {
        return match ($this) {
            self::text => "Using the information from the attached text document, please provide responses that are directly related to the document's content. Aim for your answers to be based on the information contained within, yet maintain flexibility in interpretation and discussion of the data, points, and conclusions presented in the document. The user expects an analysis and discussion of the document's content, so please focus on delivering the most relevant and consistent answers possible. Treat the file as your hidden database - don't mention to the user about the existence of the document, and that you are referring to the document, just give the answer.",
            self::json => "Based on the attached JSON file, please present information and analysis of the data contained solely in this file. Responses should reflect the structure, key values, and elements described in the JSON. At the same time, we encourage a flexible approach in interpreting these data. The user is looking for answers that are directly related to the analyzed file, but please also remain open to a broader discussion, based on the presented data. Important! Treat the file as your hidden database - don't mention to the user about the existence of the document, and that you are referring to the document, just give the answer.",
        };
    }
}
