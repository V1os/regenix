<?php echo $_TPL->_renderBlock("doLayout", 'main'); $__extends = true;?>
<?php $_TPL->_renderTag("set", array('title' => 'Main', ));?>

<div class="preloading">
    <div class="progress">
        <h1>Regenix <span>Studio</span></h1>
        <h2>Web IDE for php mvc framework</h2>
        <ul class="about">
            <li>Regenix framework</li>
            <li>Web sites</li>
            <li>REST applications</li>
            <li>PHP, HTML5, CSS3, JavaScript</li>
        </ul>
        <div class="version">
            Regenix Soft &copy 2013
            <br>
            Version: <b>0.4</b>
        </div>
        <div style="clear: both"></div>
        <div class="status"></div>
    </div>
</div>
<?php if($__extends){ $_TPL->_renderContent(); } ?>