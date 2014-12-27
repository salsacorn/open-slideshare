<div class="row">
<div class="col-md-12">
    <?php echo $this->Form->create('User', array(
    'inputDefaults' => array(
    'div' => 'form-group',
    'wrapInput' => false,
    'class' => 'form-control'
    ),
    'class' => 'well'
    )); ?>
    <div class="form-group">
        <fieldset>
            <legend><?php echo __('Add User'); ?></legend>
            <?php
         echo $this->Form->input('username', array('class' => 'form-control'));
         echo $this->Form->input('password', array('class' => 'form-control'));
         echo $this->Form->input('display_name', array('class' => 'form-control'));
         echo $this->Form->input('biography', array('class' => 'form-control'));
         ?>
        </fieldset>
    </div>
    <?php echo $this->Form->submit(__('Register'), array('class' => "btn btn-lg btn-primary btn-block")); ?>
    </form>
</div>
</div>
</div>
