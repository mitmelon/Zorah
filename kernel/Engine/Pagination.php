<?php
namespace Manomite\Engine;

class Pagination
{
    public $totalRows = 0;
    public $perPage = 10;
    public $numLinks = 2;
    public $currentPage = 1; // 1-based page number
    public $firstLink = 'Previous';
    public $nextLink = 'Next';
    public $prevLink = 'Previous';
    public $lastLink = 'Next';
    public $firstTagOpen = '';
    public $firstTagClose = ' ';
    public $lastTagOpen = ' ';
    public $lastTagClose = '';
    public $curTagOpen = '<li class="ml-3 c5byz"><span class="btn bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700" style="color: #000080; opacity: 1; font-weight: 500;">';
    public $curTagClose = '</span></li>';
    public $nextTagOpen = '<li class="ml-3 c5byz">';
    public $nextTagClose = '</li>';
    public $prevTagOpen = '<li class="ml-3 c5byz">';
    public $prevTagClose = '</li>';
    public $numTagOpen = '<li class="ml-3 c5byz"><span class="btn bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 cai0a cgpig">';
    public $numTagClose = '</span></li>';
    public $showCount = true;
    public $currentOffset = 0;
    public $template = '<a class="btn bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 cai0a cgpig {trigger}" id="page-{page}" href="#01" style="color: #000080; opacity: 1; font-weight: 500; {disabled}" {disabledAttr}>{text}</a>';
    public $parentDiv = '<ul class="flex justify-center">';
    public $parentCloseDiv = '</ul>';
    private $trigger;

    public function __construct($params = [], $trigger = 'defaultEventI')
    {
        if (count($params) > 0) {
            $this->initialize($params);
        }
        $this->trigger = $trigger;
    }

    private function initialize($params = [])
    {
        foreach ($params as $key => $val) {
            if (property_exists($this, $key)) {
                $this->$key = $val;
            }
        }
    }

    public function createLinks()
    {
        // Validate inputs
        if (!is_numeric($this->totalRows) || $this->totalRows < 0) {
            $this->totalRows = 0;
        }
        if (!is_numeric($this->perPage) || $this->perPage <= 0) {
            $this->perPage = 10;
        }
        if (!is_numeric($this->currentPage) || $this->currentPage < 1) {
            $this->currentPage = 1;
        }

        // Calculate number of pages
        $numPages = ceil($this->totalRows / $this->perPage);

        // Ensure currentPage is within bounds
        if ($this->currentPage > $numPages) {
            $this->currentPage = $numPages;
        }
        if ($this->currentPage < 1) {
            $this->currentPage = 1;
        }

        // Calculate offset
        $this->currentOffset = ($this->currentPage - 1) * $this->perPage;

        // Initialize output
        $output = '';
        $info = '';

        // Generate info string
        if ($this->showCount) {
            $from = $this->totalRows > 0 ? $this->currentOffset + 1 : 0;
            $to = min($this->currentOffset + $this->perPage, $this->totalRows);
            $info = "Showing <span class=\"cj0iv cv09y cmg0a\">{$from}</span> to <span class=\"cj0iv cv09y cmg0a\">{$to}</span> of <span class=\"cj0iv cv09y cmg0a\">{$this->totalRows}</span> results";
        }

        // Generate pagination links
        $output .= $this->parentDiv;

        // Previous link
        if ($this->currentPage > 1) {
            $prevPage = $this->currentPage - 1;
            $prevOffset = ($prevPage - 1) * $this->perPage;
            $output .= $this->prevTagOpen . $this->template($prevOffset, $prevPage, $this->prevLink, '') . $this->prevTagClose;
        } else {
            $output .= $this->prevTagOpen . $this->template(0, 1, $this->prevLink, 'color: #9CA3AF; opacity: 0.5; cursor: not-allowed; pointer-events: none;', 'disabled="disabled"') . $this->prevTagClose;
        }

        // Next link
        if ($this->currentPage < $numPages) {
            $nextPage = $this->currentPage + 1;
            $nextOffset = ($nextPage - 1) * $this->perPage;
            $output .= $this->nextTagOpen . $this->template($nextOffset, $nextPage, $this->nextLink, '') . $this->nextTagClose;
        } else {
            $output .= $this->nextTagOpen . $this->template(($numPages - 1) * $this->perPage, $numPages, $this->nextLink, 'color: #9CA3AF; opacity: 0.5; cursor: not-allowed; pointer-events: none;', 'disabled="disabled"') . $this->nextTagClose;
        }

        $output .= $this->parentCloseDiv;

        return [
            'html' => $output,
            'info' => $info,
            'offset' => (int) $this->currentOffset,
            'limit' => (int) $this->perPage
        ];
    }

    private function template($offset, $page, $text, $disabled, $disabledAttr = '')
    {
        $t = str_replace('{text}', $text, $this->template);
        $t = str_replace('{trigger}', $this->trigger, $t);
        $t = str_replace('{page}', $page, $t);
        $t = str_replace('{disabled}', $disabled, $t);
        $t = str_replace('{disabledAttr}', $disabledAttr, $t);
        return $t;
    }
}
?>