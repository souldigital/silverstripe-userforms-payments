<% if $Submission %>
    <% if $Submission.Payment.Status == Captured %>
        {$OnSuccessMessage}
    <% else %>
        {$OnErrorMessage}
    <% end_if %>
<% end_if %>