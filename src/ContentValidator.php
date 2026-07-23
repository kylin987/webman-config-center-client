<?php

namespace Yhs\WebmanConfigCenter;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

final class ContentValidator
{
    public function validate(ConfigItem $item, string $expectedFormat): void
    {
        if ($item->format !== $expectedFormat || md5($item->content) !== $item->md5) {
            throw new InvalidArgumentException('配置格式或内容校验值不匹配');
        }
        match ($item->format) {
            'php' => $this->php($item->content),
            'json' => json_decode($item->content, true, 512, JSON_THROW_ON_ERROR),
            'yaml', 'yml' => Yaml::parse($item->content, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE),
            'ini' => $this->ini($item->content),
            'txt' => null,
            default => throw new InvalidArgumentException('不支持的配置格式'),
        };
    }

    private function php(string $content): void
    {
        $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($content);
        if (count($statements) !== 1 || !$statements[0] instanceof Stmt\Return_ || !$statements[0]->expr) {
            throw new InvalidArgumentException('PHP 配置必须是单个 return 静态表达式');
        }
        $this->literal($statements[0]->expr);
    }

    private function literal(Node $node): void
    {
        if ($node instanceof Scalar\String_ || $node instanceof Scalar\LNumber || $node instanceof Scalar\DNumber) return;
        if ($node instanceof Expr\ConstFetch && in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true)) return;
        if ($node instanceof Expr\UnaryMinus || $node instanceof Expr\UnaryPlus) {
            $this->literal($node->expr);
            return;
        }
        if ($node instanceof Expr\Array_) {
            foreach ($node->items as $item) {
                if (!$item instanceof Expr\ArrayItem || !$item->value) throw new InvalidArgumentException('数组项无效');
                if ($item->key) $this->literal($item->key);
                $this->literal($item->value);
            }
            return;
        }
        throw new InvalidArgumentException('PHP 配置不能包含动态表达式');
    }

    private function ini(string $content): void
    {
        if (parse_ini_string($content, true, INI_SCANNER_RAW) === false) {
            throw new InvalidArgumentException('INI 配置无效');
        }
    }
}

