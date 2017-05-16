<?php
class Pages extends CI_Controller {

    public function view($page = 'home')
    {
        echo $page;
        if ( ! file_exists(APPPATH.'views/page/'.$page.'.php'))
        {
            // Whoops, we don't have a page for that!
            show_404();
        }

        $data['title'] = ucfirst($page); // Capitalize the first letter

        $this->load->view('templates/header', $data);
        $this->load->view('page/'.$page, $data);
        $this->load->view('templates/footer', $data);
    }
}