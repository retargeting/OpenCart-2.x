<!--
    VIEW FILE - Catalog template
    catalog/view/theme/default/template/module
    retargeting.tpl

    MODULE: Retargeting
-->
<!-- START RETARGETING MODULE -->
<script type="text/javascript">

    /* -- For use with v3 only -- */
    /*
    (function(){
        var ra_key = "<?php echo $api_key_field; ?>";
        var ra = document.createElement("script");
        ra.type ="text/javascript";
        ra.async = true;
        ra.src = ("https:" == document.location.protocol ? "https://" : "http://") + "retargeting-data.eu/rajs/" + ra_key + ".js";
        var s = document.getElementsByTagName("script")[0];
        s.parentNode.insertBefore(ra,s);
    })
    ();
    */

    /* -- For use with v2 -- */
    (function(){
        var ra = document.createElement("script");
        ra.type ="text/javascript";
        ra.async = true;
        ra.src = ("https:" == document.location.protocol ? "https://" : "http://") + "retargeting-data.eu/" + document.location.hostname.replace("www.","") + "/ra.js";
        var s = document.getElementsByTagName("script")[0];
        s.parentNode.insertBefore(ra,s);
    })
    ();

    <?php echo $js_output; ?>

</script>
<!-- END RETARGETING MODULE -->