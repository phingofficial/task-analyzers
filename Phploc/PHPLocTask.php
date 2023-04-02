<?php

/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://phing.info>.
 */

namespace Phing\Task\Ext\Analyzer\Phploc;

use Phing\Exception\BuildException;
use Phing\Io\File;
use Phing\Task;
use Phing\Type\Element\FileSetAware;
use Phing\Util\StringHelper;
use SplFileInfo;

/**
 * Runs phploc a tool for quickly measuring the size of PHP projects.
 *
 * @package phing.tasks.ext.phploc
 * @author  Raphael Stolt <raphael.stolt@gmail.com>
 */
class PHPLocTask extends Task
{
    use FileSetAware;

    /**
     * @var array
     */
    protected $suffixesToCheck = ['php'];

    /**
     * @var array
     */
    protected $acceptedReportTypes = ['cli', 'txt', 'xml', 'csv', 'json'];

    /**
     * @var null
     */
    protected $reportDirectory = null;

    /**
     * @var string
     */
    protected $reportType = 'cli';

    /**
     * @var string
     */
    protected $reportFileName = 'phploc-report';

    /**
     * @var bool
     */
    protected $countTests = false;

    /**
     * @var null|File
     */
    protected $fileToCheck = null;

    /**
     * @var array
     */
    protected $filesToCheck = [];

    /**
     * @var PHPLocFormatterElement[]
     */
    protected $formatterElements = [];

    /**
     * @var string
     */
    private $pharLocation = "";

    /**
     * @param string $suffixListOrSingleSuffix
     */
    public function setSuffixes($suffixListOrSingleSuffix)
    {
        if (strpos($suffixListOrSingleSuffix, ',')) {
            $suffixes = explode(',', $suffixListOrSingleSuffix);
            $this->suffixesToCheck = array_map('trim', $suffixes);
        } else {
            $this->suffixesToCheck[] = trim($suffixListOrSingleSuffix);
        }
    }

    /**
     * @param File $file
     */
    public function setFile(File $file)
    {
        $this->fileToCheck = trim($file);
    }

    /**
     * @param boolean $countTests
     */
    public function setCountTests($countTests)
    {
        $this->countTests = StringHelper::booleanValue($countTests);
    }

    /**
     * @param string $type
     */
    public function setReportType($type)
    {
        $this->reportType = trim($type);
    }

    /**
     * @param string $name
     */
    public function setReportName($name)
    {
        $this->reportFileName = trim($name);
    }

    /**
     * @param string $directory
     */
    public function setReportDirectory($directory)
    {
        $this->reportDirectory = trim($directory);
    }

    /**
     * @param string $pharLocation
     */
    public function setPharLocation($pharLocation)
    {
        $this->pharLocation = $pharLocation;
    }

    /**
     * @param PHPLocFormatterElement $formatterElement
     */
    public function addFormatter(PHPLocFormatterElement $formatterElement)
    {
        $this->formatterElements[] = $formatterElement;
    }

    /**
     * @throws BuildException
     */
    protected function loadDependencies()
    {
        if (!empty($this->pharLocation)) {
            // hack to prevent PHPLOC from starting in CLI mode and halting Phing
            eval(
                "namespace SebastianBergmann\PHPLOC\CLI;
class Application
{
    public function run() {}
}"
            );

            ob_start();
            include $this->pharLocation;
            ob_end_clean();
        }

        if (!class_exists('\SebastianBergmann\PHPLOC\Analyser')) {
            if (!@include_once 'SebastianBergmann/PHPLOC/autoload.php') {
                throw new BuildException(
                    'PHPLocTask depends on PHPLoc being installed and on include_path.',
                    $this->getLocation()
                );
            }
        }
    }

    public function main()
    {
        $this->loadDependencies();

        $this->validateProperties();

        if (count($this->filesets) > 0) {
            foreach ($this->filesets as $fileSet) {
                $directoryScanner = $fileSet->getDirectoryScanner($this->project);
                $files = $directoryScanner->getIncludedFiles();
                $directory = $fileSet->getDir($this->project)->getPath();

                foreach ($files as $file) {
                    if ($this->isFileSuffixSet($file)) {
                        $this->filesToCheck[] = $directory . DIRECTORY_SEPARATOR . $file;
                    }
                }
            }

            $this->filesToCheck = array_unique($this->filesToCheck);
        }

        $this->runPhpLocCheck();
    }

    /**
     * @throws BuildException
     */
    private function validateProperties()
    {
        if ($this->fileToCheck === null && count($this->filesets) === 0) {
            throw new BuildException('Missing either a nested fileset or the attribute "file" set.');
        }

        if ($this->fileToCheck !== null) {
            if (!file_exists($this->fileToCheck)) {
                throw new BuildException("File to check doesn't exist.");
            }

            if (!$this->isFileSuffixSet($this->fileToCheck)) {
                throw new BuildException('Suffix of file to check is not defined in "suffixes" attribute.');
            }

            if (count($this->filesets) > 0) {
                throw new BuildException('Either use a nested fileset or "file" attribute; not both.');
            }
        }

        if (count($this->suffixesToCheck) === 0) {
            throw new BuildException('No file suffix defined.');
        }

        if (count($this->formatterElements) == 0) {
            if ($this->reportType === null) {
                throw new BuildException('No report type or formatters defined.');
            }

            if ($this->reportType !== null && !in_array($this->reportType, $this->acceptedReportTypes)) {
                throw new BuildException('Unaccepted report type defined.');
            }

            if ($this->reportType !== 'cli' && $this->reportDirectory === null) {
                throw new BuildException('No report output directory defined.');
            }

            if ($this->reportDirectory !== null && !is_dir($this->reportDirectory)) {
                $reportOutputDir = new File($this->reportDirectory);

                $logMessage = "Report output directory doesn't exist, creating: "
                    . $reportOutputDir->getAbsolutePath() . '.';

                $this->log($logMessage);
                $reportOutputDir->mkdirs();
            }

            if ($this->reportType !== 'cli') {
                $this->reportFileName .= '.' . $this->reportType;
            }

            $formatterElement = new PHPLocFormatterElement();
            $formatterElement->setType($this->reportType);
            $formatterElement->setUseFile($this->reportDirectory !== null);
            $formatterElement->setToDir($this->reportDirectory);
            $formatterElement->setOutfile($this->reportFileName);
            $this->formatterElements[] = $formatterElement;
        }
    }

    /**
     * @param string $filename
     *
     * @return boolean
     */
    protected function isFileSuffixSet($filename)
    {
        return in_array(pathinfo($filename, PATHINFO_EXTENSION), $this->suffixesToCheck);
    }

    protected function runPhpLocCheck()
    {
        $files = $this->getFilesToCheck();
        $count = $this->getCountForFiles($files);

        foreach ($this->formatterElements as $formatterElement) {
            $formatter = PHPLocFormatterFactory::createFormatter($formatterElement);

            if ($formatterElement->getType() != 'cli') {
                $logMessage = 'Writing report to: '
                    . $formatterElement->getToDir() . DIRECTORY_SEPARATOR . $formatterElement->getOutfile();

                $this->log($logMessage);
            }

            $formatter->printResult($count, $this->countTests);
        }
    }

    /**
     * @return SplFileInfo[]
     */
    protected function getFilesToCheck()
    {
        $files = [];

        if (count($this->filesToCheck) > 0) {
            foreach ($this->filesToCheck as $file) {
                $files[] = (new SplFileInfo($file))->getRealPath();
            }
        } elseif ($this->fileToCheck !== null) {
            $files = [(new SplFileInfo($this->fileToCheck))->getRealPath()];
        }

        return $files;
    }

    /**
     * @param SplFileInfo[] $files
     *
     * @return array
     */
    protected function getCountForFiles(array $files)
    {
        $analyserClass = '\\SebastianBergmann\\PHPLOC\\Analyser';
        $analyser = new $analyserClass();

        return $analyser->countFiles($files, $this->countTests);
    }
}
