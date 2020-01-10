<?php
    if(!defined("CORE_FOLDER")) die();
    $LANG   = $module->lang;
    $CONFIG = $module->config;
    Helper::Load("Money");
?>
<form action="<?php echo Controllers::$init->getData("links")["controller"]; ?>" method="post" id="ExampleSSLSettings">
    <input type="hidden" name="operation" value="module_controller">
    <input type="hidden" name="module" value="ExampleSSL">
    <input type="hidden" name="controller" value="settings">

    <div class="formcon">
        <div class="yuzde30"><?php echo $LANG["fields"]["username"]; ?></div>
        <div class="yuzde70">
            <input type="text" name="username" value="<?php echo $CONFIG["settings"]["username"]; ?>">
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30"><?php echo $LANG["fields"]["password"]; ?></div>
        <div class="yuzde70">
            <input type="password" name="password" value="<?php echo $CONFIG["settings"]["password"] ? "*****" : ""; ?>">
        </div>
    </div>

    <div class="formcon" style="display: none;">
        <div class="yuzde30"><?php echo $LANG["fields"]["test-mode"]; ?></div>
        <div class="yuzde70">
            <input<?php echo $CONFIG["settings"]["test-mode"] ? ' checked' : ''; ?> type="checkbox" name="test-mode" value="1" id="ExampleSSL_test-mode" class="checkbox-custom">
            <label class="checkbox-custom-label" for="ExampleSSL_test-mode">
                <span class="kinfo"><?php echo $LANG["desc"]["test-mode"]; ?></span>
            </label>
        </div>
    </div>

    <div class="clear"></div>
    <br>

    <div style="float:left;" class="guncellebtn yuzde30"><a id="ExampleSSL_testConnect" href="javascript:void(0);" class="lbtn"><i class="fa fa-plug" aria-hidden="true"></i> <?php echo $LANG["test-button"]; ?></a></div>


    <div style="float:right;" class="guncellebtn yuzde30"><a id="ExampleSSL_submit" href="javascript:void(0);" class="yesilbtn gonderbtn"><?php echo $LANG["save-button"]; ?></a></div>

</form>
<script type="text/javascript">
    $(document).ready(function(){
        $("#ExampleSSL_testConnect").click(function(){
            $("#ExampleSSLSettings input[name=controller]").val("test_connection");
            MioAjaxElement($(this),{
                waiting_text:waiting_text,
                progress_text:progress_text,
                result:"ExampleSSL_handler",
            });
        });

        $("#ExampleSSL_submit").click(function(){
            $("#ExampleSSLSettings input[name=controller]").val("settings");
            MioAjaxElement($(this),{
                waiting_text:waiting_text,
                progress_text:progress_text,
                result:"ExampleSSL_handler",
            });
        });
    });

    function ExampleSSL_handler(result){
        if(result != ''){
            var solve = getJson(result);
            if(solve !== false){
                if(solve.status == "error"){
                    if(solve.for != undefined && solve.for != ''){
                        $("#ExampleSSLSettings "+solve.for).focus();
                        $("#ExampleSSLSettings "+solve.for).attr("style","border-bottom:2px solid red; color:red;");
                        $("#ExampleSSLSettings "+solve.for).change(function(){
                            $(this).removeAttr("style");
                        });
                    }
                    if(solve.message != undefined && solve.message != '')
                        alert_error(solve.message,{timer:5000});
                }else if(solve.status == "successful")
                    alert_success(solve.message,{timer:2500});
            }else
                console.log(result);
        }
    }
</script>