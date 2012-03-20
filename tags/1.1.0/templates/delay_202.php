<?php
/**
 * Template which is shown when the user account has been updated and needs to be delayed login.
 *
 * Parameters:
 * - 'target': Target URL.
 * - 'params': Parameters which should be included in the request.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package SimpleSAMLphp-GoogleApps
 * @version $Id$
 */

$this->data['header'] = $this->t('{googleapps:AuthProcess:202_title}');
$this->data['202_header'] = $this->t('{googleapps:AuthProcess:202_header}');
$this->data['202_text'] = $this->t('{googleapps:AuthProcess:202_' . $this->data['reason'] . '}',
                                   array('%UNTIL%' => @date('g:i A', $this->data['until']),
                                         '%URL%' => ($this->data['restartURL'] ? $this->data['restartURL'] : '#')));

$this->includeAtTemplateBase('includes/header.php');
?>
<h1><?php echo $this->data['202_header']; ?></h1>
<p><?php echo $this->data['202_text']; ?></p>
<?php
$this->includeAtTemplateBase('includes/footer.php');
