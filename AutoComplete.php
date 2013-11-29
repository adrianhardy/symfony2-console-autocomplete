<?php

namespace Diginuity\AutoComplete;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 *
 * In-string searching, over-typing auto-complete tool for the command line
 *
 * Inspired by the magic in Symfony\Component\Console\Helper\DialogHelper, but
 * because a lot of the magic in DialogHelper is bundled into the large "ask"
 * function (or in the case of hasSttyAvailable(), is private) I needed to
 * re-implement a lot of that magic here.
 *
 * So, while that component got me started, most of the code has been rewritten
 * to behave exactly how I want it to behave. Most of my effort so far has
 * gone on the "user experience" when using this tool.
 *
 * @author Adrian Hardy <ah@adrianhardy.co.uk>
 * @since 23rd Feb 2013
 */
class AutoComplete
{

    /**
     * @var string the long hex code representing the terminal settings before
     * we started messing with them
     */
    protected $startSttyMode;

    /**
     * @var ConsoleOutput
     */
    protected $output;


    /**
     * @var the single most important variable - tracks cursor pos
     */
    protected $cursorPosition = 0;

    /**
     * @var int the currently selected suggestion
     */
    protected $currentSuggestion = -1;

    /**
     * @var array the current suggested matches for the search
     */
    protected $matches = array();

    /**
     * @var array a map between the match 0-index and the autocomplete keys
     */
    protected $returnMapping = array();


    /**
     * @var string Our current search phrase
     */
    protected $search = '';

    /**
     * @var array the options we want to sift through
     */
    protected $autocomplete = array();

    /**
     * @var bool
     */
    protected $clearOnSuccess = false;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array $autocomplete
     */
    public function __construct(OutputInterface $output, array $autocomplete) {
        $this->autocomplete = $autocomplete;
        $this->output = $output;
    }


    /**
     * When the user hits "return" because they're satisfied with their choice,
     * the prompt will simply vanish, rather than remaining on screen.
     *
     * @param bool $clearOnSuccess
     * @return $this
     */
    public function setClearOnSuccess($clearOnSuccess = true) {
        $this->clearOnSuccess = $clearOnSuccess;
        return $this;
    }

    /**
     * If we've not set up a style name, create on of our own.
     */
    protected function setupStyle() {

        if (!$this->output->getFormatter()->hasStyle('hl')) {
            $this->output->getFormatter()->setStyle('hl', new OutputFormatterStyle('white', null, array('bold')));
        }

    }

    /**
     * Provide a shorthand for writing lots of stuff
     */
    protected function write($message) {
        $this->output->write($message);
        return $this;
    }

    /**
     * Unfortunately, there's still this big chunk of code in the middle
     *
     * @param $prompt
     * @return mixed an array key
     */
    public function autocomplete($prompt) {

        $this->inputStream = STDIN;
        $this->output->write($prompt);

        $this->setupStyle();
        $this->initStty();

        while ($c = fread($this->inputStream, 1)) {

            $backspace = ("\177" === $c);
            $escSequence = ("\033" === $c);
            $controlChars = (ord($c) < 32);

            switch (true) {
                case $backspace:
                    if ($this->cursorPosition > 0) {
                        $this->cursorPosition--;
                        // Move cursor backwards
                        $this->output->write("\033[1D");
                        $this->output->write("\033[K");

                        // chop off the last char off our search string
                        if ($this->search) {
                            $this->search = substr($this->search, 0, strlen($this->search) - 1);
                        }

                    }

                    if ($this->cursorPosition === 0) {
                        $this->currentSuggestion = -1;
                    }

                    $this->checkMatches();
                    break;

                case $escSequence:
                    $c .= fread($this->inputStream, 2);

                    // A = Up Arrow. B = Down Arrow
                    if ('A' === $c[2] || 'B' === $c[2]) {
                        if ('A' === $c[2] && -1 === $this->currentSuggestion) {
                            $this->currentSuggestion = 0;
                        }

                        if (0 === count($this->matches)) {
                            continue;
                        }

                        // wrap the suggestions around
                        $this->currentSuggestion += ('A' === $c[2]) ? -1 : 1;
                        $this->currentSuggestion = (count($this->matches) + $this->currentSuggestion) % count($this->matches);
                    }
                    break;

                case $controlChars:
                    if ("\n" === $c) {
                        if (!empty($this->matches) && -1 !== $this->currentSuggestion) {
                            $this->return = $this->returnMapping[$this->currentSuggestion];
                            break 2;
                        } else {
                            $this->output->write("\007"); // bell
                        }
                    }

                    if ("\t" === $c) {
                        if (!empty($this->matches)) {
                            $this->output->writeln("");
                            foreach ($this->matches as $match) {
                                $this->output->writeln(" - " . $match);
                            }
                            $this->cursorPosition = 0;
                            $this->output->write($prompt);
                        }
                    }
                    break;

                default: // they're just typing - we'll output the chars
                    $this->output->write($c);
                    $this->search .= $c;
                    $this->cursorPosition++;

                    $this->checkMatches();
                    break;
            }


            // this is where the fiddly bits start
            // the only control chars we have available are either:


            if (count($this->matches) > 0) {
                $this->output->write("\033[K");
                $this->suggestMatch();
            }

            // if there are no matches (potentially as a result of us
            // backspacing too far or hitting a duff char), then go back to
            // the start of the line, clear it and rewrite out the search
            // string
            if (empty($this->matches) && $this->cursorPosition > 0) {
                $this->output->write("\033[{$this->cursorPosition}D");
                $this->output->write("\033[K");
                $this->output->write($this->search);
                $this->cursorPosition = strlen($this->search);
                $this->currentSuggestion = -1;
            }

            // $this->debug();

        }

        if ($this->clearOnSuccess) {
			$len = strlen($prompt) + $this->cursorPosition;
            $this->output->write("\033[{$len}D");
            $this->output->write("\033[K");
        }

        $this->restoreStty();

        if (!$this->clearOnSuccess) {
            $this->output->writeln("");
        }
        return $this->return;
    }

    /**
     * Finding the match and just writing it is easy, the problem we've got is
     * I want people to continue over-typing the match results, "in place".
     *
     * This function does some jiggery pokery related to currentPosition to
     * ensure that the cursor jumps around to the most appropriate position.
     *
     * \033[{$i}D           move back $i chars
     * \033[{$i}C           move forward $i chars
     * \033[K               clear to the end of the line
     *
     */
    protected function suggestMatch() {
        $suggestion = $this->matches[$this->currentSuggestion];
        $start = stripos($suggestion, $this->search);
        $len = strlen($this->search);

        // jump back to the start of the line
        if ($this->cursorPosition > 0) {
            $this->write("\033[{$this->cursorPosition}D");
        }

        // write out the non-search-text part of the match
        $this->write(substr($suggestion, 0, $start));
        // highlight the part that matches our search string
        $this->write("<hl>" . substr($suggestion, $start, $len) . "</hl>");
        // write out the rest of the match
        $this->write(substr($suggestion, $start + strlen($this->search)));

        // cursor is now at the end of the suggestion string, and we want
        // the cursor to be at the end of their search string
        $match_len = strlen($suggestion);

        // move all the way to start of line (position 0)
        $this->output->write("\033[{$match_len}D");

        // move the cursor to the position of our search string in the
        // suggestion
        if ($start > 0) {
            // this control code moves forward one, even if $start is 0
            $this->output->write("\033[{$start}C");
        }

        // move forward the length of the search string
        $this->output->write("\033[{$len}C");

        // modify cursorPosition, and we're ready to continue typing
        $this->cursorPosition = $start + $len;
    }

    protected function checkMatches() {
        $this->matches = array();
        $this->returnMapping = array();

        foreach ($this->autocomplete as $key => $value) {
            if (strlen($this->search) > 2 && stristr($value, $this->search)) {
                $this->matches[] = $value;
                $this->returnMapping[] = $key;
            }
        }

        $this->currentSuggestion = empty($this->matches) ? -1 : 0;
    }

    protected function debug() {
        // save cursor position
        $this->output->write("\033[s");

        $stats = array(
            'cursor pos' => $this->cursorPosition,
            'search' => $this->search,
            'matches (size)' => count($this->matches),
            'suggestion idx' => $this->currentSuggestion
        );

        $pos = 10;
        foreach ($stats as $name => $stat) {
            $this->write("\033[{$pos};80H")
                ->write("\033[K")
                ->write($name . ' = ' . $stat);
            $pos++;
        }

        // restore cursor position
        $this->write("\033[u");
    }

    protected function restoreStty() {
        shell_exec('stty ' . $this->startSttyMode);
    }

    protected function initStty() {
        $this->startSttyMode = shell_exec('stty -g');
        // -echo stops echoing keypresses to stdout
        // -icanon enable a bunch of special chars
        shell_exec('stty -icanon -echo');

    }

}

