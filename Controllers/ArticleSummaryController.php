<?php

class FreshExtension_ArticleSummary_Controller extends Minz_ActionController
{
    public function summarizeAction()
    {
        $this->view->_layout(false);

        $oai_url = FreshRSS_Context::$user_conf->oai_url;
        $oai_key = FreshRSS_Context::$user_conf->oai_key;
        $oai_model = FreshRSS_Context::$user_conf->oai_model;
        $oai_prompt = FreshRSS_Context::$user_conf->oai_prompt;
        $oai_provider = FreshRSS_Context::$user_conf->oai_provider;

        if (
            $this->isEmpty($oai_url)
            || $this->isEmpty($oai_key)
            || $this->isEmpty($oai_model)
            || $this->isEmpty($oai_prompt)
        ) {
            echo json_encode(array(
                'response' => array(
                    'data' => 'missing config',
                    'error' => 'configuration'
                ),
                'status' => 200
            ));
            return;
        }

        $entry_id = Minz_Request::param('id');
        $entry_dao = FreshRSS_Factory::createEntryDao();
        $entry = $entry_dao->searchById($entry_id);

        if ($entry === null) {
            echo json_encode(array('status' => 404));
            return;
        }

        $content = $entry->content(); // 替换为你的文章内容 - Replace with article content

        // 处理 $oai_url
        $oai_url = rtrim($oai_url, '/'); // 去除末尾的斜杠
        // 构建 GET 请求 URL
        $url = $oai_url . '/chat/completions' . '?' . http_build_query(array(
                'model' => $oai_model,
                'messages' => json_encode([
                    [
                        'role' => 'system',
                        'content' => $oai_prompt
                    ],
                    [
                        'role' => 'user',
                        'content' => "input: \n" . $this->htmlToMarkdown($content),
                    ]
                ]),
                'max_tokens' => 2048, // Adjust summary length if necessary
                'temperature' => 0.7, // Adjust the randomness if necessary
                'n' => 1 // Generate one summary
            ));
        var_dump($url);
        // 初始化 cURL 会话 - Initialize cURL session
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $oai_key,
            'Content-Type: application/json',
        ));

        // 执行请求 - Execute the request
        $response = curl_exec($ch);

        // 检查请求是否成功 - Check if the request was successful
        if ($response === false) {
            echo json_encode(array('error' => curl_error($ch)));
        } else {
            // 如果请求成功 - If the request was successful
            $decodedResponse = json_decode($response, true);
            $summary = null;

            // 检查生成结果 - Check if the response contains generated text
            if (isset($decodedResponse['choices'][0]['message']['content'])) {
                $summary = $decodedResponse['choices'][0]['message']['content'];
            }

            // 返回响应 - Return response
            $successResponse = array(
                'response' => array(
                    'data' => $summary ? $summary : 'no summary generated',
                    'error' => null
                ),
                'status' => 200
            );
        }

        // 关闭 cURL 会话 - Close the cURL session
        curl_close($ch);
    }


    private
    function isEmpty($item)
    {
        return $item === null || trim($item) === '';
    }

    private
    function htmlToMarkdown($content)
    {
        // 创建 DOMDocument 对象 - Creating DOMDocument objects
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // 忽略 HTML 解析错误 - Ignore HTML parsing errors
        $dom->loadHTML('<?xml encoding="UTF-8">' . $content);
        libxml_clear_errors();

        // 创建 XPath 对象 - Create XPath objects
        $xpath = new DOMXPath($dom);

        // 定义一个匿名函数来处理节点 - Define an anonymous function to process the node
        $processNode = function ($node, $indentLevel = 0) use (&$processNode, $xpath) {
            $markdown = '';

            // 处理文本节点 - Processing text nodes
            if ($node->nodeType === XML_TEXT_NODE) {
                $markdown .= trim($node->nodeValue);
            }

            // 处理元素节点 - Processing element nodes
            if ($node->nodeType === XML_ELEMENT_NODE) {
                switch ($node->nodeName) {
                    case 'p':
                    case 'div':
                        foreach ($node->childNodes as $child) {
                            $markdown .= $processNode($child);
                        }
                        $markdown .= "\n\n";
                        break;
                    case 'h1':
                        $markdown .= "# ";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "\n\n";
                        break;
                    case 'h2':
                        $markdown .= "## ";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "\n\n";
                        break;
                    case 'h3':
                        $markdown .= "### ";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "\n\n";
                        break;
                    case 'h4':
                        $markdown .= "#### ";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "\n\n";
                        break;
                    case 'h5':
                        $markdown .= "##### ";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "\n\n";
                        break;
                    case 'h6':
                        $markdown .= "###### ";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "\n\n";
                        break;
                    case 'a':
                        // $markdown .= "[";
                        // $markdown .= $processNode($node->firstChild);
                        // $markdown .= "](" . $node->getAttribute('href') . ")";
                        $markdown .= "`";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "`";
                        break;
                    case 'img':
                        $alt = $node->getAttribute('alt');
                        $markdown .= "img: `" . $alt . "`";
                        break;
                    case 'strong':
                    case 'b':
                        $markdown .= "**";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "**";
                        break;
                    case 'em':
                    case 'i':
                        $markdown .= "*";
                        $markdown .= $processNode($node->firstChild);
                        $markdown .= "*";
                        break;
                    case 'ul':
                    case 'ol':
                        $markdown .= "\n";
                        foreach ($node->childNodes as $child) {
                            if ($child->nodeName === 'li') {
                                $markdown .= str_repeat("  ", $indentLevel) . "- ";
                                $markdown .= $processNode($child, $indentLevel + 1);
                                $markdown .= "\n";
                            }
                        }
                        $markdown .= "\n";
                        break;
                    case 'li':
                        $markdown .= str_repeat("  ", $indentLevel) . "- ";
                        foreach ($node->childNodes as $child) {
                            $markdown .= $processNode($child, $indentLevel + 1);
                        }
                        $markdown .= "\n";
                        break;
                    case 'br':
                        $markdown .= "\n";
                        break;
                    case 'audio':
                    case 'video':
                        $alt = $node->getAttribute('alt');
                        $markdown .= "[" . ($alt ? $alt : 'Media') . "]";
                        break;
                    default:
                        // 未考虑到的标签，只保留内部文字内容 - Tags not considered, only the text inside is kept
                        foreach ($node->childNodes as $child) {
                            $markdown .= $processNode($child);
                        }
                        break;
                }
            }

            return $markdown;
        };

        // 获取所有节点 - Get all nodes
        $nodes = $xpath->query('//body/*');

        // 处理所有节点 - Process all nodes
        $markdown = '';
        foreach ($nodes as $node) {
            $markdown .= $processNode($node);
        }

        // 去除多余的换行符 - Remove extra line breaks
        $markdown = preg_replace('/(\n){3,}/', "\n\n", $markdown);

        return $markdown;
    }

}
