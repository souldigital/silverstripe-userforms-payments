<% if $Content %>
	{$Content}
<% end_if %>

{$Body}

<% if HideFormData %>
	<% if $Payment.Status == "Captured" %>
        <table style="margin-top: 20px;">
            <tbody>
            <tr>
                <th style="text-align: left;">Your payment amount</th>
                <td>${$Payment.Amount.Nice}</td>
            </tr>
            </tbody>
        </table>
	<% end_if %>
<% end_if %>

<% if HideFormData %>
<% else %>
    <dl>
		<% loop Fields %>
            <dt><strong><% if Title %>$Title<% else %>$Name<% end_if %></strong></dt>
            <dd style="margin: 4px 0 14px 0">$FormattedValue</dd>
		<% end_loop %>

		<% if $Payment.Status %>
            <dt><strong>Payment Status</strong></dt>
            <dd style="margin: 4px 0 14px 0">{$Payment.Status}</dd>
		<% end_if %>

		<% if $PaymentID %>
            <dt><strong>Payment Number:</strong></dt>
            <dd style="margin: 4px 0 14px 0">{$PaymentID}</dd>
		<% end_if %>

	    <%-- this is the account info, not the same as the above ID--%>
		<% if $ReceiptNumber %>
            <dt><strong>Receipt Number:</strong></dt>
            <dd style="margin: 4px 0 14px 0">{$ReceiptNumber}</dd>
		<% end_if %>
    </dl>
<% end_if %>