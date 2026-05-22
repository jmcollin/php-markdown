<?php

declare(strict_types=1);

namespace PhpMarkdown\Lexer;

enum TokenType
{
    case HEADING;
    case PARAGRAPH;
    case FENCED_CODE;
    case LIST_ITEM;
    case BLOCKQUOTE;
    case HORIZONTAL_RULE;
    case BLANK;
    case TABLE_ROW;
    case TABLE_SEPARATOR;
    case LINK_DEFINITION;
    case HTML_BLOCK;
    case COLUMNS_CONTAINER;
}
