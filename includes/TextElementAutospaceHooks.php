<?php

class TextElementAutospaceHooks {

    /** 页面语言缓存 */
    private ?string $pageLang = null;

    /** 目标语言名单 */
    private static array $targetLanguages = [
        'ja','zh','lzh','yue','wuu','nan','cdo','cpx','gan','hsn','hak'
    ];

    /** 排除语言标记名单 */
    private static array $excludedMarkers = ['-latn'];

    /** 保留分隔符（用于单字符和保留模式检查） */
    private static array $delimiters = ['|', '•', '·'];

    /** Spacer 模板缓存 */
    private static ?DOMElement $spacerTemplate = null;

    /** 特殊字符正则缓存 */
    private static ?string $specialCharRegex = null;

    /**
     * Parser hook
     */
    public static function onParserAfterTidy(Parser $parser, &$text) {
        $instance = new self();
        $instance->pageLang = $parser->getTitle()?->getPageLanguage()?->getCode() ?? null;
        $text = $instance->processHtml($text);
    }

    /**
     * 一次 DOM 遍历处理整个页面
     */
    private function processHtml(string $html): string {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) $this->traverseAndProcess($body);

        if ($body) {
            $html = '';
            foreach ($body->childNodes as $child) $html .= $dom->saveHTML($child);
            return $html;
        }
        return preg_replace('/^<!DOCTYPE.*?>|<\/?(html|body)>/i','',$dom->saveHTML());
    }

    /**
     * 深度遍历 DOM 树，同时处理 .space-around 元素
     */
    private function traverseAndProcess(DOMNode $node) {
        if ($node instanceof DOMElement) {
            if ($this->hasClass($node,'space-around')) {
                $lang = $this->getElementLanguage($node);
                if ($this->isTargetLanguage($lang)) {
                    self::processElement($node);
                }
            }
            foreach ($node->childNodes as $child) $this->traverseAndProcess($child);
        }
    }

    /** 获取元素语言 */
    private function getElementLanguage(DOMElement $element): ?string {
        if ($element->hasAttribute('lang')) return $element->getAttribute('lang');
        $parent = $element->parentNode;
        while ($parent instanceof DOMElement) {
            if ($parent->hasAttribute('lang')) return $parent->getAttribute('lang');
            $parent = $parent->parentNode;
        }
        return $this->pageLang;
    }

    /** 判断是否为目标语言 */
    private function isTargetLanguage(?string $langCode): bool {
        if (!$langCode) return false;
        foreach (self::$excludedMarkers as $marker) {
            if (str_contains($langCode,$marker)) return false;
        }
        foreach (self::$targetLanguages as $lang) {
            if ($langCode === $lang || str_starts_with($langCode,$lang.'-')) return true;
        }
        return false;
    }

    /** 处理单个 .space-around 元素 */
    private static function processElement(DOMElement $element) {
        $doc = $element->ownerDocument;
        if (!self::$spacerTemplate) {
            self::$spacerTemplate = $doc->createElement('span',' ');
            self::$spacerTemplate->setAttribute('class','text-element-space');
        }
        self::processSibling($element,'previousSibling',true);
        self::processSibling($element,'nextSibling',false);
    }

    /**
     * 统一处理左右相邻节点
     */
    private static function processSibling(DOMElement $element,string $siblingProp,bool $isLeft) {
        $sibling = $element->$siblingProp;
        if (!$sibling) return;

        if ($sibling instanceof DOMElement && self::hasClass($sibling,'space-around')) return;

        $insert = false;

        if ($sibling instanceof DOMText) {
            $text = $sibling->wholeText;
            if (self::matchesPattern($text, !$isLeft)) return;

            $charFunc = $isLeft ? 'getLastNonSpaceChar' : 'getFirstNonSpaceChar';
            $char = self::$charFunc($text);

            if ($char !== null && !self::isDelimiter($char) && !self::isSpecialChar($char)) {
                $insert = true;
                // 移除一个紧邻的空格
                if (($isLeft && mb_substr($text,-1)===' ') || (!$isLeft && mb_substr($text,0,1)===' ')) {
                    $newText = $isLeft ? mb_substr($text,0,-1) : mb_substr($text,1);
                    if ($newText==='') $sibling->parentNode->removeChild($sibling);
                    else $sibling->nodeValue = $newText;
                }
            } elseif ($char === null) {
                // 文本全是空白字符 -> 需要插入 spacer 并移除整个文本节点
                $insert = true;
                $sibling->parentNode->removeChild($sibling);
            }
        } elseif ($sibling instanceof DOMElement) {
            $insert = true;
        }

        if ($insert) {
            $span = self::$spacerTemplate->cloneNode(true);
            if ($isLeft) $element->insertBefore($span,$element->firstChild);
            else $element->appendChild($span);
        }
    }

    /** 匹配保留模式（前后空格+分隔符） */
    private static function matchesPattern(string $text,bool $fromStart=false): bool {
        foreach(self::$delimiters as $d){
            $pattern = " $d ";
            if($fromStart){
                if(mb_substr($text,0,mb_strlen($pattern))===$pattern) return true;
            }else{
                if(mb_substr($text,-mb_strlen($pattern))===$pattern) return true;
            }
        }
        return false;
    }

    /** 获取最后一个非空格字符 */
    private static function getLastNonSpaceChar(string $text): ?string {
        $trimmed = rtrim($text);
        if($trimmed==='') return null;
        return mb_substr($trimmed,-1);
    }

    /** 获取第一个非空格字符 */
    private static function getFirstNonSpaceChar(string $text): ?string {
        $trimmed = ltrim($text);
        if($trimmed==='') return null;
        return mb_substr($trimmed,0,1);
    }

    /** 是否分隔符 */
    private static function isDelimiter(string $char): bool {
        return in_array($char,self::$delimiters,true);
    }

    /** 是否特殊字符（标点、全角、空白等） */
    private static function isSpecialChar(string $char): bool {
        if(!self::$specialCharRegex){
            self::$specialCharRegex='/[\x{3000}-\x{3003}\x{3008}-\x{3011}\x{FF01}-\x{FF60}\x{3014}-\x{301F}\x{2000}-\x{205F}\x{0009}\x{00A0}]/u';
        }
        return preg_match(self::$specialCharRegex,$char)||(preg_match('/\s/u',$char)&&$char!==' ');
    }

    /** 判断元素是否包含指定 class */
    private static function hasClass(DOMElement $element,string $class): bool {
        return in_array($class,explode(' ',$element->getAttribute('class')));
    }

    /** 页面输出 hook */
    public static function onBeforePageDisplay(OutputPage $out,Skin $skin){
        $out->addInlineStyle('.text-element-space{word-spacing:-2px;user-select:none}');
    }
}