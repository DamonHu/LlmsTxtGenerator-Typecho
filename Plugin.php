<?php
/**
 * typecho动态生成符合标准的 llms.txt 文件
 * @package LlmsTxtGenerator
 * @author DamonHu
 * @version 1.0.0
 * @link https://github.com/DamonHu/LlmsTxtGenerator-Typecho
 */
class LlmsTxtGenerator_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 添加路由：当访问 /llms.txt 时，交由本插件的 renderLlms 方法处理
        Helper::addRoute('llms_txt', '/llms.txt', 'LlmsTxtGenerator_Plugin', 'renderLlms');
        return _t('插件已激活！请访问 您的域名/llms.txt 查看效果。');
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        // 移除路由
        Helper::removeRoute('llms_txt');
        return _t('插件已禁用，llms.txt 路由已移除。');
    }

    /**
     * 获取插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $description = new Typecho_Widget_Helper_Form_Element_Textarea(
            'siteDescription',
            null,
            '专注于技术分享与独立开发的个人博客。',
            _t('网站简短介绍'),
            _t('用于在 llms.txt 顶部呈现给 AI 的摘要说明，建议包含核心关键词。')
        );
        $form->addInput($description);

        $includeTypes = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'includeTypes',
            array(
                'post' => _t('独立文章 (Posts)'),
                'page' => _t('独立页面 (Pages)'),
                'category' => _t('分类归档 (Categories)')
            ),
            array('post', 'page'),
            _t('包含的内容类型'),
            _t('请选择需要暴露给 AI 爬虫的内容类型。')
        );
        $form->addInput($includeTypes);

        $postLimit = new Typecho_Widget_Helper_Form_Element_Text(
            'postLimit',
            null,
            '30',
            _t('文章最大精选数量'),
            _t('设置输出最新文章的最大数量（默认 30 篇）。')
        );
        $form->addInput($postLimit->addRule('isInteger', _t('请输入有效的整数')));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * Typecho 路由回调的默认入口方法
     * 必须存在此方法，否则会报 Call to undefined method 错误
     */
    public function execute()
    {
        // 直接调用下方的渲染逻辑
        self::renderLlms();
    }
    /**
     * 动态渲染 llms.txt 内容
     */
    public static function renderLlms()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $db = Typecho_Db::get();
        
        // 获取插件配置
        $pluginOptions = $options->plugin('LlmsTxtGenerator');
        $siteDescription = $pluginOptions->siteDescription;
        $includeTypes = $pluginOptions->includeTypes ? $pluginOptions->includeTypes : array();
        $postLimit = intval($pluginOptions->postLimit) ? intval($pluginOptions->postLimit) : 30;

        // 初始化 Markdown 内容
        $markdown = "# " . $options->title . "\n\n";
        if (!empty($siteDescription)) {
            $markdown .= "> " . trim($siteDescription) . "\n\n";
        }

        // 1. 处理分类
        if (in_array('category', $includeTypes)) {
            $categories = $db->fetchAll($db->select()->from('table.metas')->where('type = ?', 'category'));
            if (!empty($categories)) {
                $markdown .= "## 分类归档 (Categories)\n";
                foreach ($categories as $category) {
                    $categoryUrl = Typecho_Router::url('category', $category, $options->index);
                    $markdown .= "- [" . $category['name'] . "](" . $categoryUrl . "): " . ($category['description'] ? $category['description'] : $category['name'] . ' 分类下的文章') . "\n";
                }
                $markdown .= "\n";
            }
        }

        // 2. 处理页面
        if (in_array('page', $includeTypes)) {
            $pages = $db->fetchAll($db->select()->from('table.contents')
                ->where('type = ?', 'page')
                ->where('status = ?', 'publish')
                ->order('created', Typecho_Db::SORT_DESC));

            if (!empty($pages)) {
                $markdown .= "## 独立页面 (Pages)\n";
                foreach ($pages as $page) {
                    $pageUrl = Typecho_Router::url('page', $page, $options->index);
                    $markdown .= "- [" . $page['title'] . "](" . $pageUrl . ")\n";
                }
                $markdown .= "\n";
            }
        }

        // 3. 处理文章
        if (in_array('post', $includeTypes)) {
            $posts = $db->fetchAll($db->select()->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('created', Typecho_Db::SORT_DESC)
                ->limit($postLimit));

            if (!empty($posts)) {
                $markdown .= "## 精选文章 (Posts)\n";
                foreach ($posts as $post) {
                    $postUrl = Typecho_Router::url('post', $post, $options->index);
                    $summary = $post['text'] ? self::getPostSummary($post['text']) : $post['title'];
                    $markdown .= "- [" . $post['title'] . "](" . $postUrl . "): " . $summary . "\n";
                }
                $markdown .= "\n";
            }
        }

        // 清除之前的缓冲区，强制输出纯文本格式
        ob_clean();
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=3600'); // 缓存1小时，减轻数据库压力
        echo $markdown;
        exit;
    }

    /**
     * 辅助函数：截取干净的文章摘要
     */
    private static function getPostSummary($text, $length = 80)
    {
        $text = str_replace('', '', $text);
        $text = strip_tags($text);
        $text = preg_replace('/\[.*?\]\(.*?\)/', '', $text);
        $text = preg_replace('/[#*`\-\n\r]/', ' ', $text);
        return mb_strimwidth(trim($text), 0, $length, '...');
    }
}